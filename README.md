# php-logstash
php实现的轻量级日志文件监控

说明
------

通过这个轻巧的脚本可以很容易的将日志送到 elasticsearch 中，并且本地测试处理能力基本保持在接近1w/s的速度。

脚本有2个部分,输入和输出。 
输入 php logstash.php --listen=case.log 用来监听访问日志更新,或者使用tail -F case.log | php logstash.php --listen 来监听来自stdin的输入

输出 php logstash.php --indexer 用来建立索引，该脚本每秒约索引8千左右，也可开多个并行处理。

调试命令 php logstash.php --build=1 在本地输出 case.log 里追加一条log。

## 依赖

* PHP 5.4.0 +
* redis 扩展
* curl 扩展

## 使用方法

### 输入方式

tail -F case.log | php logstash.php --listen

带配置文件

tail -F case.log | php agent.php --listen

或者指定文件并从头开始索引

php agent.php --listen=case.log

### 索引方式

php logstash.php --indexer

带配置文件

php agent.php --indexer

可将以上命令放置在shell中执行

```
#/bin/bash
nohup tail -F access.log | php agent.php --listen &
nohup php agent.php --listen=case.log & 
nohup php agent.php --indexer &
```

调试方式

程序提供了一个指令用来模拟日志写入

```
php logstash.php --build=<log_number> #生成的log条目数，默认20万条

文件保存为case.log并且在同级目录下，可用 

tail -F case.log | php agent.php --listen

通过以上命令测日志监听状态，并从redis中查看结果，或重新定义parser方法在内部中断调试日志解析过程
```

全部指令

```
logstash.php --listen=<file_path> #将脚本设置为输入模式，用来监听日志文件输入

logstash.php --listen  #不指定文件将监听来自 stdin 的输入

logstash.php --indexer #将脚本设置为索引模式，用来从队列发送到ElasticSearch服务器

agent.php     #调用自定义配置文件并由该文件引导
```



## 配置文件

```
全部配置文件如下,默认均有默认值
[
     'host'              => '127.0.0.1',              # redis地址
     'port'              => 6379,                     # redis 默认端口
     'type'              => 'log'                     # redis 队列key,及es的index type
     'agent_log'         => __DIR__ .'/agent.log',    # 日志保存地址
     'input_sync_memory' => 5*1024*1024               # 输入信息到达指定内存后同步
     'input_sync_second' => 5                         # 输入信息等待超过指定秒数后同步，以上2个条件共同触发
     'parser'            => [$this,'parser']          # 自定义输入端日志的处理格式，默认与程序提供的logformat json一致

     'elastic_host'      => 'http://127.0.0.1:9200/'  # elastic search通信地址
     'elastic_user'      => '',                       # es 用户名             
     'elastic_pwd'       => '',                       # es 密码 程序采用 http auth_basic 认证方式，其他认证暂不支持
     'prefix'            => 'phplogstash',            # es 默认索引前缀名字为 phplogstash-2015.12.12 
];
```


## 日志格式

程序默认使用如下Nginx的log_format
需要将 log_format 放置在 nginx 的 http 配置内

```
  log_format json '{"timestamp":"$time_iso8601",'
               '"host":"$server_addr",'
               '"server":"$server_name",'
               '"client":"$http_x_forwarded_for",'
               '"size":$body_bytes_sent,'
               '"responsetime":$upstream_response_time,'
               '"domain":"$host",'
               '"method":"$request_method",'
               '"url":"$uri",'
               '"requesturi":"$request_uri",'
               '"via":"$server_protocol",'
               '"request":"$request",'
               '"uagent":"$http_user_agent",'
               '"referer":"$http_referer",'
               '"status":"$status"}';


如果是内网机器需要使用该变量获取真实IP     $http_x_forwarded_for

使用如下配置设置自定义日志格式，需要将该配置放在 server 的配置内
access_log web_accesslog.json json
```

生成的日志格式入如下，默认build的也是这种格式

```
{
  "timestamp": "2015-12-18T14:24:26+08:00",
  "_version": "1",
  "host": "10.10.23.139",
  "message": "0",
  "server": "localhost",
  "client": "127.0.0.1",
  "size": 197,
  "responsetime": 0.010,
  "domain": "www.localhost.com",
  "method": "GET",
  "url": "/index.php",
  "requesturi": "/controller/action?arg1=1&arg2=2",
  "via": "HTTP/1.1",
  "request": "GET /controller/action?arg1=1&arg2=2 HTTP/1.1",
  "uagent": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
  "referer": "-",
  "status": "200"
}
```

默认的parser会把request的请求分解成如下样式，然后提交给elasticsearch
```
Array
(
    [timestamp] => 2015-12-18T14:24:26+08:00
    [_version] => 1
    [host] => 10.10.23.139
    [message] => 0
    [server] => localhost
    [client] => 127.0.0.1
    [size] => 197
    [responsetime] => 0.01
    [domain] => www.localhost.com
    [method] => GET
    [url] => /index.php
    [requesturi] => /controller/action?arg1=1&arg2=2
    [via] => HTTP/1.1
    [uagent] => Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)
    [referer] => -
    [status] => 200
    [resquesturi] => /controller/action
    [args] => Array
        (
            [arg1] => 1
            [arg2] => 2.7.1
        )
)
```


##异常处理

脚本启动后会持续监听日志输入，在没有PHP致命错误、无内存泄露的情况下，该脚本不会中断。

即使redis连接断开脚本也不会退出,而是以1秒一次的重连等待redis恢复，所以理论上该脚本不会异常中断。
