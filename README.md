# php-logstash
php实现的轻量级日志文件监控

说明
------

通过这个轻巧的脚本可以很容易的将日志送到 elasticsearch 的嘴巴里，并且本地测试处理能力基本保持在1w/s的速度。
脚本有2个部分，输入和输出。 输入 logstash.php listen 用来监听访问日志更新,输出 logstash.php indexer 用来建立索引。
调试命令 logstash.php build 1 在本地输出 case.log 里追加一条log。

## Requirements

* PHP 5.4.0 +
* redis 扩展
* curl 扩展

## 使用方法

输入方式

tail -F access.log | php logstash.php listen -f conf.ini


输出

php logstash indexer -f conf.ini

可将以上命令放置在shell中执行


```
#/bin/bash
nohup tail -F access.log | php logstash.php listen -f conf.ini &
nohup php logstash indexer -f conf.ini &
```

调试

程序提供了一个指令用来模拟日志写入

```
php logstash.php build <log_number> #生成的log条目数，默认50万条

文件保存为case.log并且在同级目录下，可用 

tail -F case.log | php logstash.php listen -f conf.ini

测试执行步骤
```

全部指令

```
logstash.php listen #将脚本设置为输入模式，用来监听日志文件输入

logstash.php indexer #将脚本设置为索引模式，用来从队列发送到ElasticSearch服务器

logstash.php -f     #自定义配置文件名称，可以为空，默认走程序默认配置
```



## 配置文件

```
全部配置文件如下,默认均有默认值
[
     'host'              => '127.0.0.1',              # redis地址
     'port'              => 6379,                     # redis 默认端口
     'list_key'          => 'log'                     # redis 队列key
     'agent_log'         => __DIR__ .'/agent.log',    # 日志保存地址
     'input_sync_memory' => 5*1024*1024               # 输入信息到达指定内存后同步
     'input_sync_second' => 10                        # 输入信息等待超过指定秒数后同步，以上2个条件共同触发
     'parser'            => [$this,'parser']          # 自定义输入端日志的处理格式，默认与提供的nginx logformat一致

     'elastic_host'      => 'http://127.0.0.1:9200/'  # elastic search通信地址
     'elastic_user'      => '',                       # es 用户名             
     'elastic_pwd'       => '',                       # es 密码 程序采用 http auth_basic 认证方式，其他认证暂不支持
];
```


## 日志格式

程序默认使用如下Nginx的log_format
需要将 log_format 放置在 nginx 的 http 配置内

```
 log_format json '{"@timestamp":"$time_iso8601",'
               '"@version":"1",'
               '"host":"$server_addr",'
               '"client":"$remote_addr",'
               '"size":$body_bytes_sent,'
               '"responsetime":$upstream_response_time,'
               '"domain":"$host",'
               '"url":"$uri",'
               '"request":"$request",'
               '"uagent":"$http_user_agent",'
               '"referer":"$http_referer",'
               '"status":"$status"}';

如果是内网机器需要使用该变量获取真实IP     $http_x_forwarded_for

使用如下配置设置自定义日志格式，需要将该配置放在 server 的配置内
access_log web_accesslog.json json
```

##异常处理

脚本从tail -F 启动后会持续监听日志输入，在没有PHP致命错误时，该脚本不会中断。

即使redis连接断开脚本也不会退出,而是以1秒一次的重连等待redis恢复，所以理论上该脚本不会异常中断。
