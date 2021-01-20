<?php

use RingCentral\Psr7\Response;

// 设置API访问URL 例：https://yourapi.com/meting
define('API_URI', 'Your API address');
// 设置中文歌词
define('LYRIC_CN', true);
// 设置文件缓存及时间
//注意，阿里云FC的运行环境为只读文件系统，所以这里的文件式CACHE不可用，切勿设置为TRUE
//后续可能会更新挂载NAS或连接数据库作为缓存的代码，但是当前切勿修改为True！否则会出现错误。
define('CACHE', false);
define('CACHE_TIME', 3600);
// 设置AUTH密钥，若设置了密钥则访问时参数需带上密钥
define('AUTH', false);
define('AUTH_SECRET', 'meting-secret');
// 设置网易云解析Cookie，如果不是解析网易云VIP歌曲可以不使用
define('USE_NETEASY_COOKIE', false);
define('NETEASY_COOKIE', 'YOUR-COOKIES ON NETEASY MUSIC');

require __DIR__ .'/Meting.php';
use Metowolf\Meting;

function handler($request, $context): Response{
    /*
    $body       = $request->getBody()->getContents();
    $queries    = $request->getQueryParams();
    $method     = $request->getMethod();
    $headers    = $request->getHeaders();
    $path       = $request->getAttribute('path');
    $requestURI = $request->getAttribute('requestURI');
    $clientIP   = $request->getAttribute('clientIP');
    */
    $_GET = $request->getQueryParams();
    $_SERVER = $request->getHeaders();
    $_SERVER['REQUEST_TIME'] = time();
    $retcode = 200;
    $retheader = array();
    $retdata = "";


    //Check if is set necessary args
    if (!isset($_GET['type']) || !isset($_GET['id'])) {
        $retcode = 400;
        $retdata = json_encode(array(
            'code' => 400,
            'error' => 'Missing necessary args.'
        ));
        return new Response($retcode,$retheader,$retdata);
    }
    $server = isset($_GET['server']) ? $_GET['server'] : 'netease';
    $type = $_GET['type'];
    $id = $_GET['id'];

    //Check if is set AUTH info
    if (AUTH) {
        $auth = isset($_GET['auth']) ? $_GET['auth'] : '';
        if (in_array($type, ['url', 'cover', 'lrc'])) {
            if ($auth == '' || $auth != auth($server . $type . $id)) {
                $retcode = 403;
                return new Response($retcode,$retheader,$retdata);
            }
        }
    }

    // data rules
    if (in_array($type, ['song', 'playlist'])) {
        $retheader['content-type'] = "application/json; charset=utf-8;";
    } elseif (in_array($type, ['lrc'])) {
        $retheader['content-type'] = "text/plain; charset=utf-8;";
    }

    // Allow cross-site
    $retheader['Access-Control-Allow-Origin'] = "*";
    $retheader['Access-Control-Allow-Methods'] = "GET";


    $api = new Meting($server);
    $api->format(true);

    // Set cookie
    if(USE_NETEASY_COOKIE){
        if ($server == 'netease') {
            $api->cookie(NETEASY_COOKIE);
        }
    }

    if ($type == 'playlist') {

        // If has cache and it's not outdated, then just use it
        if (CACHE) {
            $file_path = __DIR__ . '/cache_' . $server . '_' . $id . '.json';
            if (file_exists($file_path)) {
                if ($_SERVER['REQUEST_TIME'] - filectime($file_path) < CACHE_TIME) {
                    $retdata = file_get_contents($file_path);
                    return new Response($retcode,$retheader,$retdata);
                }
            }
        }

        $data = $api->playlist($id);
        if ($data == '[]') {
            $retcode = 400;
            $retdata = json_encode(array(
                'code' => 400,
                'error' => 'Unknown playlist id'
            ));
            return new Response($retcode,$retheader,$retdata);
        }
        $data = json_decode($data);
        $playlist = [];
        foreach ($data as $song) {
            $playlist[] = array(
                'name'   => $song->name,
                'artist' => implode('/', $song->artist),
                'url'    => API_URI . '?server=' . $song->source . '&type=url&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'url' . $song->url_id) : ''),
                'cover'  => API_URI . '?server=' . $song->source . '&type=cover&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'cover' . $song->url_id) : ''),
                'lrc'    => API_URI . '?server=' . $song->source . '&type=lrc&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'lrc' . $song->url_id) : '')
            );
        }
        $playlist = json_encode($playlist);

        // Update cache
        if (CACHE) {
            // ! mkdir /cache/playlist
            file_put_contents($file_path, $playlist);
        }

        $retdata = $playlist;
        return new Response($retcode,$retheader,$retdata);
    }else {
        
        $song = $api->song($id);
        if ($song == '[]') {
            $retcode = 400;
            $retdata = json_encode(array(
                'code' => 400,
                'error' => 'Unknown song id'
            ));
            return new Response($retcode,$retheader,$retdata);
        }
        $song = json_decode($song)[0];

        switch ($type) {
            case 'name':
                $retdata = $retdata.$song->name;
                break;
            
            case 'artist':
                $retdata = $retdata.implode('/', $song->artist);
                break;

            case 'url':
                $m_url = json_decode($api->url($song->url_id, 320))->url;
                if (!$m_url) {
                    $retcode = 417;
                    $retdata = json_encode(array(
                        'code' => 417,
                        'error' => 'Phrase url error, may be an cookie error'
                        ));
                    return new Response($retcode,$retheader,$retdata);
                }
                if ($m_url[4] != 's') {
                    $m_url = str_replace('http', 'https', $m_url);
                }
                $retheader['Location'] = $m_url;
                $retcode = 302;
                return new Response($retcode,$retheader,$retdata);
                break;

            case 'cover':
                $c_url = json_decode($api->pic($song->pic_id, 90))->url;
                $retheader['Location'] = $c_url;
                $retcode = 302;
                return new Response($retcode,$retheader,$retdata);
                break;

            case 'lrc':
                $lrc_data = json_decode($api->lyric($song->lyric_id));
                if ($lrc_data->lyric == '') {
                    $retdata = $retdata.'[00:00.00]这似乎是一首纯音乐呢，请尽情欣赏它吧！';
                    return new Response($retcode,$retheader,$retdata);
                }
                if ($lrc_data->tlyric == '') {
                    $retdata = $retdata.$lrc_data->lyric;
                    return new Response($retcode,$retheader,$retdata);
                }

                if (LYRIC_CN) {
                    $lrc_arr = explode("\n", $lrc_data->lyric);
                    $lrc_cn_arr = explode("\n", $lrc_data->tlyric);
                    $lrc_cn_map = [];
                    foreach ($lrc_cn_arr as $i => $v) {
                        if ($v == '') continue;
                        $line = explode(']', $v);
                        $lrc_cn_map[$line[0]] = $line[1];
                        unset($lrc_cn_arr[$i]);
                    }
                    foreach ($lrc_arr as $i => $v) {
                        if ($v == '') continue;
                        $key = explode(']', $v)[0];
                        if (!empty($lrc_cn_map[$key]) && $lrc_cn_map[$key] != '//') {
                            $lrc_arr[$i] .= ' (' . $lrc_cn_map[$key] . ')';
                            unset($lrc_cn_map[$key]);
                        }
                    }
                    $retdata = $retdata.implode("\n", $lrc_arr);
                    return new Response($retcode,$retheader,$retdata);
                }
                $retdata = $retdata.$lrc_data->lyric;
                break;
            case 'single':
                $single = array(
                    'name'   => $song->name,
                    'artist' => implode('/', $song->artist),
                    'url'    => API_URI. '?server=' . $song->source . '&type=url&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'url' . $song->url_id) : ''),
                    'cover'  => API_URI . '?server=' . $song->source . '&type=cover&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'cover' . $song->url_id) : ''),
                    'lrc'    => API_URI . '?server=' . $song->source . '&type=lrc&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'lrc' . $song->url_id) : '')
                );
                $retdata = $retdata.json_encode($single);
                break;
            default:
                $retcode = 400;
                $retdata = json_encode(array(
                    'code' => 400,
                    'error' => 'Unknown type'
                ));
                return new Response($retcode,$retheader,$retdata);

        }

    }


    return new Response($retcode,$retheader,$retdata);
}

function auth($name)
{
    return hash_hmac('sha1', $name, AUTH_SECRET);
}
