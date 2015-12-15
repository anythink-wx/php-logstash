# php-logstash
php实现的轻量级日志文件监控

说明
------

通过这个轻巧的脚本可以轻松的将accesslog送到elasticsearch的嘴巴里，并且本机测试处理能力基本保持在1w/s的速度。
脚本有2个部分，输入和输出。 输入 logstash.php listen ,输出 logstash.php send。
调试命令 logstash.php build 1 在本地输出 case.log 里追加一条log。

## Requirements

* PHP 5.4.0 +
* redis 扩展
* curl 扩展

## Installation

下载脚本，放置在随便一个地方。

更多待补充。

## nginx 自定义格式

```
 log_format json '{"@timestamp":"$time_iso8601",'
               '"@version":"1",'
               '"host":"$server_addr",'
               '"client":"$http_x_forwarded_for",'
               '"size":$body_bytes_sent,'
               '"responsetime":$upstream_response_time,'
               '"domain":"$host",'
               '"url":"$uri",'
               '"request":"$request",'
               '"uagent":"$http_user_agent",'
               '"referer":"$http_referer",'
               '"status":"$status"}';
```
