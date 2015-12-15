<?php
/**
 * Created by PhpStorm.
 * User: anythink
 * Date: 15/12/13
 * Time: 下午7:07
 */

$config = [
	'host' => '127.0.0.1',
	'port' => 6379,
];

if(isset($GLOBALS['argv'][1])){
	(new LogStash())->handler($config)->$GLOBALS['argv'][1]();
}else{
	(new LogStash())->handler($config)->listen();
}



class LogStash{
	private $config;
	private $redis;
	protected $message;
	private $RedisRetryCount = 0;
	private $begin;

	/**
	 * @param array $config
	 */
	function handler(array $cfg=[]){
		$this->default_value($cfg,'host','127.0.0.1');
		$this->default_value($cfg,'port',6379);
		$this->default_value($cfg,'agent_log',__DIR__ .'/agent.log');
		$this->default_value($cfg,'list_key','log');
		$this->default_value($cfg,'input_sync_memory',5*1024*1024);
		$this->default_value($cfg,'input_sync_second',10);
		$this->default_value($cfg,'parser',[$this,'parser']);

		$this->default_value($cfg,'elastic_host','http://127.0.0.1:9200/');
		$this->default_value($cfg,'elastic_user');
		$this->default_value($cfg,'elastic_pwd');
		$this->config = $cfg;
		$this->redis();
		return $this;
	}


	/**
	 * redis 链接
	 */
	private function redis(){
		try {
			$redis = new redis();
			$redis->pconnect($this->config['host'],$this->config['port'],0);
			$this->redis = $redis;
			$this->redis->ping();
		}catch (Exception $e){
			$this->log($e->getMessage() .' now retrying');
			sleep(1); //如果报错等一秒再重连
			$this->redis();
		}
	}

	/**
	 * 获取stdin输入
	 */
	function listen(){
		$this->begin = time();
		$this->log('begin in ' . $this->begin,'debug');
		while($line = fgets(STDIN)){
			$this->message[] = call_user_func($this->config['parser'],$line);
			$this->inputAgent();
		}
	}


	function output(){
		while($res = $this->redis->brPop($this->config['list_key'],0)) {

		}
	}

	/**
	 * 创建假数据
	 */
	public function build(){
		$all_count = isset($GLOBALS['argv'][2]) ? $GLOBALS['argv'][2] : 500000;
		$start = microtime(true);
		$s = "";
		for($i=0;$i<$all_count;$i++){
			$s .= '{"@timestamp":"'.date('c').'","@version":"'.$i.'","host":"10.10.23.139","client":"39.190.84.155","size":17298,"responsetime":0.104,"domain":"v10.gaonengfun.com","url":"/index.php","request":"GET /article/list?appkey=1&v=2.7.0&ch=baidu&waterfall_page=1&tag_id=618,620,1523,1598,1606,1611,1888,1903,1959,1976,2017,2299,2503,2534,2596,2673,2739,2842,2859,3054,3056,3084,3119,3182,3201,3236,3303,3320,3474,3598,3710,3779,4186,5139,5970,6733,6977,12451,12737,23470,25371,27079,31096,31321,34342,35780,40604,48033,53427,59003,59760,60060,60303,69665,69889,308584,327138,335495,348875 HTTP/1.1","uagent":"NaoDong android 2.7.0","referer":"-","status":"200"}'.PHP_EOL;
			$rate = number_format(($i/$all_count * 100),0);
			if($rate%10 ==0) echo $rate .'%'.PHP_EOL;
			if(memory_get_usage(true)/1024/1024 >= 50){
				file_put_contents('case.log',$s,FILE_APPEND);
				$s='';
			}
		}
		if($s){
			file_put_contents('case.log',$s,FILE_APPEND);
		}
		echo 'all complete '. (microtime(true)-$start) .' seconds'.PHP_EOL;
	}




	function curl($params){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->config['elastic_host']);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_TIMEOUT,5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		if($this->config['elastic_user']){
			curl_setopt($ch, CURLOPT_USERPWD, "{($this->config['elastic_user']}:{$this->config['elastic_pwd']}");
		}

		$body = curl_exec($ch);
		$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
		if($code > 400 || $code ===0){
			throw new urlLoadException('params: ' .$params. ' code: '.$code);
		}
		curl_close($ch);
		return json_decode($body,true);
	}

	/**
	 * 默认的处理log的方法
	 * @param $message
	 * @return mixed
	 */
	private function parser($message){
		$json = json_decode($message,true);
		list($request_method,$args,$protocol) = explode(' ',$json['request']);
		list($api_interface,$params) = explode('?',$args);
		parse_str($params,$paramsOutput);
		$json['responsetime'] = (int) $json['responsetime'];
		$json['request_method'] = $request_method;
		$json['method'] = $api_interface;
		$json['params'] = $paramsOutput;
		unset($json['request']);
		return $json;
	}



	private function inputAgent(){
		$current_usage = memory_get_usage();
		$sync_second = $this->config['input_sync_second'];
		$sync_memory = $this->config['input_sync_memory'];
		$time = ($this->begin + $sync_second) - time() ;
		if((memory_get_usage() > $sync_memory) or ( $this->begin+$sync_second  < time())){
			try{
				$this->redis->ping(); //这里的存活检测还有带验证
				$pipe = $this->redis->multi(Redis::PIPELINE);
				foreach($this->message as $pack){
					$pipe->lPush($this->config['list_key'],json_encode($pack));
				}
				$replies = $pipe->exec();
				$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
					' current: '.$time.'s ','sync');
			}catch (Exception $e){
				$this->log('multi push error :' . $e->getMessage());
				$this->redis();
			}
			$this->begin = time(); //reset begin time
			unset($this->message); //reset message count
		}
	}

	private function sendAgent(){
		$current_usage = memory_get_usage();
		$sync_second = $this->config['input_sync_second'];
		$sync_memory = $this->config['input_sync_memory'];
		$time = ($this->begin + $sync_second) - time() ;
		if((memory_get_usage() > $sync_memory) or ( $this->begin+$sync_second  < time())){

			try{
				$this->redis->ping();
			}catch(Exception $e){
				$this->log($e->getMessage());
				$this->redis(); //重连
			}

			try{
				$pipe = $this->redis->multi(Redis::PIPELINE);
				foreach($this->message as $pack){
					$pipe->lPush($this->config['list_key'],json_encode($pack));
				}
				$replies = $pipe->exec();
				$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
					' current: '.$time.'s ','sync');
			}catch (Exception $e){
				$this->log($e->getMessage());
			}
			$this->begin = time(); //reset begin time
			unset($this->message); //reset message count
		}
	}

	private function default_value(&$arr,$k,$v = ''){
		return $arr[$k] = isset($arr[$k]) ? $arr[$k] : $v;
	}

	private function log($msg,$level='warning'){
		$message = '['.$level.'] ['.date('Y-m-d H:i:s').'] ['.(memory_get_usage()/1024/1024).'MB] ' .$msg. PHP_EOL;
		file_put_contents($this->config['agent_log'], $message, FILE_APPEND);
	}
}

