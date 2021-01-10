<?php
error_reporting(0);
//Copy MadeLineProto
if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
//End
//include MadeLineProto :
include 'madeline.php';
$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->start();
//End
//Functions :
$mess = 'خخخخخخخ';
$MadelineProto->messages->sendMessage(['peer' => 823812772 ,'message' => $mess,'parse_mode' => 'MarkDown']);
