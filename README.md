# php-logstash
php实现的轻量级日志文件监控

说明
------

通过这个轻巧的脚本可以很容易的将日志送到 elasticsearch 中，并且本地测试处理能力基本保持在接近1w/s的速度。

脚本主要实现两个功能，输入和输出。

### 输入

php agent.php --listen=case.log 用来监听访问日志的变更

或者使用命令 tail -F case.log | php agent.php --listen 来监听来自 stdin 的输入。

该功能会持续将监听到的变更记入Redis队列中同时格式化将要记录的Log。

### 输出

php agent.php --indexer 用来建立索引，该脚本每秒约索引8千左右，也可开多个并行处理。

该功能会持续将Redis队列中的数据导入 ElasticSearch 数据库中。

### 调试

php logstash.php --build=1 在本地生成的 case.log 中追加一条log。

## 依赖

* PHP 5.4.0 +
* redis 扩展
* curl 扩展

## 使用方法说明

### 输入方式

php agent.php --listen=<file_path>  从头读取文件并持续监听

tail -F case.log | php agent.php --listen 监听 Stdin 传入的数据

### 索引方式

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

文件保存为case.log并且在同级目录下，可用命令

tail -F case.log | php agent.php --listen 或

php agent.php --listen=case.log


测日志监听状态，并从redis中查看结果，或重新定义parser方法在内部中断调试日志解析过程
```

全部指令

```
agent.php --listen=<file_path> #将脚本设置为输入模式，用来监听日志文件输入

agent.php --listen  #不指定文件将监听来自 stdin 的输入

agent.php --indexer #将脚本设置为索引模式，用来将队列的数据发送到 ElasticSearch 服务器

agent.php --status #查看队列情况和处理速度

```



## 配置文件

```
全部配置文件如下,默认均有默认值
[
     'redis'             => 'tcp://127.0.0.1:6379',   # redis地址，支持认证不支持数组。认证tcp://auth:密码@127.0.0.1:6379
     'type'              => 'log'                     # redis 队列key,及es的index type
     'agent_log'         => __DIR__ .'/agent.log',    # 日志保存地址
     'input_sync_memory' => 5*1024*1024               # 输入信息到达指定内存后同步
     'input_sync_second' => 5                         # 输入信息等待超过指定秒数后同步，以上2个条件共同触发
     'parser'            => [$this,'parser']          # 自定义输入端日志的处理格式，默认与程序提供的logformat json一致

     'elastic'           => 'http://127.0.0.1:9200'  # elastic search通信地址，支持数组,可配置多个随机访问
                                                      # 支持密码 程序采用 http auth_basic 认证方式
                                                      # 使用密码 http://user:pssword@127.0.0.1:9200
     'prefix'            => 'phplogstash',            # es 默认索引前缀名字为 phplogstash-2015.12.12 
     'shards'            => '5',                      # es 分片数量
     'replicas'          => '2',                      # es 副本数量
];
```


## 日志格式

程序默认使用如下Nginx的log_format，设置步骤如下

1、将如下 log_format 规则放置在 nginx 的 http 配置内

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

2、将如下置放在 server 的配置内。

access_log web_accesslog.json json
```

生成的日志格式入如下，默认build的也是这种格式

```
{
  "timestamp": "2015-12-18T14:24:26+08:00",
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

默认的 parser 会把 request 的请求分解成resquesturi与args，然后提交给elasticsearch方便汇总查看，如果不需要这么详细的拆分请直接使用request字段即可。

```
Array
(
    [timestamp] => 2015-12-18T14:24:26+08:00
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

## 日志的归档与轮转

以下是 logrotate 的配置信息，放置在 /etc/logrotate.d 目录下，即可每日将.json 后缀的日志进行归档。

```
/data/logs/*.json  {
        daily
        missingok
        rotate 10
        dateext
        compress
        delaycompress
        notifempty
        create 644 www-data.www-data
        sharedscripts
        prerotate
                if [ -d /etc/logrotate.d/httpd-prerotate ]; then \
                        run-parts /etc/logrotate.d/httpd-prerotate; \
                fi \
        endscript
        postrotate
                invoke-rc.d nginx rotate >/dev/null 2>&1
        endscript
}
```

以上配置为Ubuntu APT 安装的脚本，自定义路径安装的请使用如下配置

```
/data/logs/*.json  {
        daily
        missingok
        rotate 10  
        dateext
        compress
        delaycompress
        notifempty
        create 644 www-data.www-data
        sharedscripts
        postrotate
                /usr/local/nginx/sbin/nginx -s reload
        endscript
}
```


##测试

```

插入测试 每秒1-2.5w每秒插入
QPS为负表示队列增加速度，为正表示队列处理速度。
========time========|========keys========|===QPS====|
2015-12-21 14:17:02 |         0          |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:03 |         0          |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:04 |         0          |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:05 |       18901        |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:06 |       46972        | -28071/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:07 |       75618        | -28646/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:08 |       102306       | -26688/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:09 |       130091       | -27785/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:10 |       155433       | -25342/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:11 |       181863       | -26430/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:12 |       199479       | -17616/s |
========time========|========keys========|===QPS====|
2015-12-21 14:17:13 |       199479       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:14 |       199479       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:15 |       199479       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:16 |       199479       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:17 |       199479       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:18 |       200030       |  -551/s  |
========time========|========keys========|===QPS====|
索引测试 8k-1w每秒
========time========|========keys========|===QPS====|
2015-12-21 14:17:47 |       200030       |   0/s    |
========time========|========keys========|===QPS====|
2015-12-21 14:17:48 |       197692       |  2338/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:49 |       186923       | 10769/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:50 |       178367       |  8556/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:51 |       168894       |  9473/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:52 |       158028       | 10866/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:53 |       149483       |  8545/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:54 |       139930       |  9553/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:55 |       129157       | 10773/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:56 |       120599       |  8558/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:57 |       111956       |  8643/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:58 |       101151       | 10805/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:17:59 |       91715        |  9436/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:00 |       83253        |  8462/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:01 |       72841        | 10412/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:02 |       62831        | 10010/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:03 |       55254        |  7577/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:04 |       44376        | 10878/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:05 |       33947        | 10429/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:06 |       26726        |  7221/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:07 |       16451        | 10275/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:08 |        5504        | 10947/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:09 |         0          |  5504/s  |
========time========|========keys========|===QPS====|
2015-12-21 14:18:10 |         0          |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:18:11 |         0          |    NA    |
========time========|========keys========|===QPS====|
2015-12-21 14:18:12 |         0          |    NA    |

```


##异常处理

脚本启动后会持续监听日志输入，在没有PHP致命错误、无内存泄露的情况下，该脚本不会中断。

即使redis连接断开脚本也不会退出,而是以1秒一次的重连等待redis恢复，所以理论上该脚本不会异常中断。
