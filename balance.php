<?php

// for error reporting on this page
ini_set('display_errors', '1');
error_reporting(E_ALL);

// not https?
if (!isset($_SERVER['HTTPS'])) {
    header("Location: https://$_SERVER[SERVER_NAME]$_SERVER[REQUEST_URI]");
    exit;
}

function v(&$arr, $key, $def) {
    return isset($arr[$key]) ? $arr[$key] : $def;
}

$bb_server = 'my.rochester.edu';
$bb_url = "https://$bb_server";
$login_path = '/webapps/login/index';
$frameset_path = '/webapps/portal/frameset.jsp';
$main_path = '/webapps/portal/execute/tabs/tabAction?tab_tab_group_id=_23_1';
$sequoia_token_path = '/webapps/bb-ecard-sso-bb_bb60/token.jsp';
$sequoia_auth_url = 'https://ecard.sequoiars.com/eCardServices/AuthenticationHandler.ashx';
$sequoia_balance_url = 'https://ecard.sequoiars.com/eCardServices/eCardServices.svc/WebHttp/GetAccountHolderInformationForCurrentUser';
$sequoia_history_url = 'https://ecard.sequoiars.com/eCardServices/eCardServices.svc/WebHttp/GetTransactionHistoryForCurrentUserSearch';
$cookies = array('cookies_enabled' => 'yes');

$sequoia_token = '';

define('POST_APPLICATION', 0);
define('POST_MULTIPART', 1);
define('POST_JSON', 2);

function bb_request($path, $post = array(), $flags = POST_APPLICATION) {
    global $bb_url;
    global $cookies;
    if ($path[0] == '/') {
        $path = $bb_url . $path;
    }

    if ($flags != POST_JSON) {
        $fields_string = http_build_query($post, '_', '&');
    }
    $cookies_string = urldecode(http_build_query($cookies, '', '; '));

    $c = curl_init($path);
    curl_setopt($c, CURLOPT_HEADER, true);
    curl_setopt($c, CURLINFO_HEADER_OUT, true);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($c, CURLOPT_COOKIE, $cookies_string);
    switch ($flags) {
        case POST_APPLICATION:
        case POST_MULTIPART:
            curl_setopt($c, CURLOPT_POST, count($post));
            if ($flags == POST_MULTIPART) {
                curl_setopt($c, CURLOPT_POSTFIELDS, $post);
            } else {
                curl_setopt($c, CURLOPT_POSTFIELDS, $fields_string);
            }
            break;
        case POST_JSON:
            curl_setopt($c, CURLOPT_POST, 1);
            curl_setopt($c, CURLOPT_POSTFIELDS, trim(json_encode($post), '"'));
            curl_setopt($c, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            break;
    }

    $data = curl_exec($c);
    //echo curl_getinfo($c, CURLINFO_HEADER_OUT);
    //echo "\n\n";
    //echo substr($data, 0, strpos($data, "\r\n\r\n"));
    //echo "\n\n";
    curl_close($c);

    preg_match_all("|Set-Cookie: (.*)\r\n|U", $data, $matches);   
    foreach ($matches[1] as $cookie_set) {
        $cookie_arr = explode('; ', $cookie_set);
        foreach ($cookie_arr as $cookie) {
            if (strpos($cookie, '=') > 0) {
                $kv = explode('=', $cookie,2);
            } else {
                $kv = array(0 => $cookie, 1 => '');
            }
            $cookies[$kv[0]] = $kv[1];
        }
    }
    //$data = substr($data, strpos($data, "\r\n\r\n") + 4);
    return $data;
}

function bb_login() {
    global $login_path;
    global $netid;
    global $pass;

    $enc_pass = base64_encode($pass);
    $data = bb_request($login_path, array('user_id'=>$netid, 'encoded_pw'=>$enc_pass, 'encoded_pw_unicode' => '.'));
    return strpos($data, 'Location: http://my.rochester.edu/webapps/portal/frameset.jsp') !== false;
}

function seq_login() {
    global $sequoia_token_path;
    global $sequoia_auth_url;
    global $sequoia_token;

    $data = bb_request($sequoia_token_path);
    $matches = array();
    if (preg_match('/name="AUTHENTICATIONTOKEN" value="([^"]+)/', $data, &$matches)) {
        $sequoia_token = $matches[1];

        $post = array('AUTHENTICATIONTOKEN' => $sequoia_token);//, 'DESTINATIONURL' => 'https://ecard.sequoiars.com/rochester/eCardCardholder/StudentOverviewPage.aspx');
        $data = bb_request($sequoia_auth_url, $post, POST_MULTIPART);

        if (strpos(substr($data, strpos($data, "\r\n\r\n")), 'No destination url posted.')) {
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

function seq_get_balance() {
    global $sequoia_balance_url;

    $data = bb_request($sequoia_balance_url, array(), POST_JSON);
    $json = json_decode(substr($data, strpos($data, "\r\n\r\n")), true);
    return $json;
}

function seq_get_hist() {
    global $sequoia_history_url;

    $data = bb_request($sequoia_history_url, array('startDateString'=>'','endDateString'=>'','fundCode'=>0), POST_JSON);
    $json = json_decode(substr($data, strpos($data, "\r\n\r\n")), true);
    return $json;
}

function mon($amount, $p = '+') {
    $m = $p;
    if ($amount < 0) {
        $amount = abs($amount);
        $m = '-';
    }
    $cents = $amount % 100;
    if ($cents < 10) $cents = '0'.$cents;
    $amount = floor($amount / 100);
    return $m.$amount.'.'.$cents;
}

// get username & password
$netid = v(&$_POST, 'netid', '');
$pass = v(&$_POST, 'pass', '');

// get vars
$get_hist = v(&$_GET, 'get_hist', false);
$get_hist = v(&$_POST, 'get_hist', $get_hist);

if ($netid != '' && $pass != '') {
    //header('Content-Type: application/json');
    $result = array();
    if (bb_login()) {
        //echo 'Blackboard success.';echo "\n";
        if (seq_login()) {
            //echo 'Got sequoia auth.';echo " token=$sequoia_token\n";
            $bal_data = seq_get_balance();
            if (!$bal_data) {
                $result['result'] = 'E';
                $result['error'] = 'Sequoia balance request error.';
            } else {
                $result['result'] = 'B';
                $result['name'] = $bal_data['d']['FullName'];
                $result['accounts'] = array();
                $item_list = $bal_data['d']['_ItemList'];
                if (count($item_list) > 0) {
                    foreach ($item_list as $item) {
                        $result['accounts'][] = array(
                            'name' => trim($item['Name']),
                            'amount' => mon($item['Balance'], ''));
                    }
                }

                if ($get_hist) {
                    $hist_data = seq_get_hist();
                    if (!$hist_data) {
                        $result['error'] = 'Sequoia history request error.';
                    } else {
                        $result['result'] = 'H';
                        $result['history'] = array();
                        $item_list = $hist_data['d']['_ItemList'];
                        if (count($item_list) > 0) {
                            foreach ($item_list as $item) {
                                $result['history'][] = array(
                                    'time' => $item['TransactionDateTimeStr'],
                                    'location' => $item['Location'],
                                    'account' => trim($item['FundName']),
                                    'amount' => mon($item['Amount']),
                                    'type' => $item['Type']
                                );
                            }
                        }
                    }
                }
            }
        } else {
            $result['result'] = 'E';
            $result['error'] = 'Sequoia token get failed.';
        }
    } else {
        $result['result'] = 'E';
        $result['error'] = 'Blackboard says incorrect username or password.';
    }
    //print_r($result);
    //echo print_r(json_decode(json_encode($result)));
    echo json_encode($result);
    flush();
    ob_flush();

    /*$descriptorspec = array(
       0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
       1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
       // 0 => array("file", "tmp/error-output.txt", "a") // stderr is a file to write to
    );

    $cwd = '/var/www/html/htdocs/users/ugrads/nbook'; // working dir
    $env = NULL; // env vars
    $process = proc_open('./bb balance -q', $descriptorspec, $pipes, $cwd, $env);

    if (is_resource($process)) {
        // $pipes now looks like this:
        // 0 => writeable handle connected to child stdin
        // 1 => readable handle connected to child stdout
        // Any error output will be appended to /tmp/error-output.txt

        fwrite($pipes[0], "$netid\n$pass\n");
        fclose($pipes[0]);

        echo stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // It is important that you close any pipes before calling
        // proc_close in order to avoid a deadlock
        $return_value = proc_close($process);

        // echo "command returned $return_value\n";
    }*/
} else {
?>

<!DOCTYPE html>
<html><head><title>Get Balance</title></head>
<body>
<form method="post" autocomplete="off" action="balance.php">
<label for="netid">Net ID:</label><input name="netid" type="text"></input>
<label for="pass">Password:</label><input name="pass" type="password"></input>
<input name="get_hist" type="checkbox" value="1">Get transaction history</input>
<input type="submit" value="Get Balance"></input>
</form>

<?php
}
?>

