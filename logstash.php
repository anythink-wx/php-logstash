<?php
/**
 * Created by PhpStorm.
 * User: anythink
 * Date: 15/12/13
 * Time: 下午7:07
 */
define('php_logstash','0.1.0');

class LogStash{
	private $config;
	private $redis;
	protected $message;
	private $begin;

	private $cmd;
	private $args;

	/**
	 * @param array $config
	 */
	function handler(array $cfg=[]){
		$opt = getopt('',['listen::','indexer::','conf::','build::']);
		switch($opt){
			case isset($opt['listen']):
				$this->cmd = 'listen';
				break;
			case isset($opt['indexer']):
				$this->cmd = 'indexer';
				break;
			case isset($opt['build']):
				$this->cmd = 'build';
				$this->args = $opt['build'];
				break;
			default:
				$this->cmd = 'listen';
				break;
		}

		$this->default_value($cfg,'host','127.0.0.1');
		$this->default_value($cfg,'port',6379);
		$this->default_value($cfg,'agent_log',__DIR__ .'/agent.log');
		$this->default_value($cfg,'list_key','log');
		$this->default_value($cfg,'input_sync_memory',5*1024*1024);
		$this->default_value($cfg,'input_sync_second',10);
		$this->default_value($cfg,'parser',[$this,'parser']);

		$this->default_value($cfg,'elastic_host','http://127.0.0.1:9200');
		$this->default_value($cfg,'elastic_user');
		$this->default_value($cfg,'elastic_pwd');
		$this->config = $cfg;
		$this->redis();
		return $this;
	}

	function run(){
		$cmd = $this->cmd;
		$this->$cmd($this->args);
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
			$line = trim($line);
			if($line){
				$this->message[] = call_user_func($this->config['parser'],$line);
				$this->inputAgent();
			}
		}
	}


	function indexer(){

		while (true) {
			$msg = $this->redis->rPop($this->config['list_key']);
			if (false !== $msg) {
				$this->message[] = $msg;
				$this->indexerAgent();
			}else{
				sleep(1);
				$this->log('waiting for queue','debug');
			}
		}
	}

	/**
	 * 创建假数据
	 * 需要内存128MB
	 */
	public function build($args){
		$all_count = !empty($args) ? $args : 200000;
		$start = microtime(true);
		$s = "";
		echo "start" . PHP_EOL;
		for($i=0;$i<$all_count;$i++){
			$s .= '{"timestamp":"'.date('c').'","host":"10.10.23.139","message":"'.$i.'","client":"39.190.84.155","size":17298,"responsetime":0.104,"domain":"v10.gaonengfun.com","url":"/index.php","request":"GET /article/list?appkey=1&v=2.7.0&ch=baidu&waterfall_page=1&udid=618,620,1523,1598,1606,1611,1888,1903,1959,1976,2017,2299,2503,2534,2596,2673,2739,2842,2859,3054,3056,3084,3119,3182,3201,3236,3303,3320,3474,3598,3710,3779,4186,5139,5970,6733,6977,12451,12737,23470,25371,27079,31096,31321,34342,35780,40604,48033,53427,59003,59760,60060,60303,69665,69889,308584,327138,335495,348875 HTTP/1.1","uagent":"anythink android 2.7.0","referer":"-","status":"200"}'.PHP_EOL;
			$rate = number_format(($i/$all_count * 100),0);
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




	function esCurl($url,$data='',$method='post'){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->config['elastic_host'].$url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_TIMEOUT,5);
		if($this->config['elastic_user']){
			curl_setopt($ch, CURLOPT_USERPWD, "{$this->config['elastic_user']}:{$this->config['elastic_pwd']}");
		}

		if($method =='post'){
			curl_setopt ( $ch, CURLOPT_POST, 1 );
			curl_setopt ( $ch, CURLOPT_POSTFIELDS, $data );
		}else{
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch,CURLOPT_HTTPHEADER,array("X-HTTP-Method-Override: $method"));
		}


		$body = curl_exec($ch);
		$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
		$err = curl_error($ch);

		$this->log('elastic search connect error  '.PHP_EOL.'code: '. $code .PHP_EOL.
		$this->config['elastic_host'].$url.' '.$method.PHP_EOL.
			'body: '.$body.PHP_EOL.
			'data: '.$data
		);
	//	sleep(1);
	//	unset($body,$code,$err,$ch);
	//	$this->esCurl($url,$data,$method);
		curl_close($ch);

		return json_decode($body,true);
	}

	function esIndices(){
		//put /d
		$indices['settings']['index'] = ['number_of_shards' => 1,'number_of_replicas'=>1,'refresh_interval'=>'10s'];
		$indices['mappings']['log']['_source'] = ['enabled' => 'false'];
		$string_not_analyzed = ['type'=>'string','index'=>'not_analyzed'];
		$indices['mappings']['log']['properties'] = [
			'timestamp' => ['type'=>'date'],
			'host'      => $string_not_analyzed,
			'client'    => $string_not_analyzed,
			'size'      => ['type'=>'integer','index'=>'not_analyzed'],
			'responsetime' => ['type'=>'float','index'=>'not_analyzed'],
			'domain' => $string_not_analyzed,
			'url'    => $string_not_analyzed,
			'request' => ['type'=>'string'],
			'uagent' => $string_not_analyzed,
			'referer' => $string_not_analyzed,
			'status' => ['type'=>'integer','index'=>'not_analyzed'],
			'params' => ['type'=>'object'],
		];
		return $indices;

		/*
		 * "mappings" : {
        "type1" : {
            "_source" : { "enabled" : false },
            "properties" : {
                "field1" : { "type" : "string", "index" : "not_analyzed" }
            }
        }
    }*/
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
				$this->redis->ping();
			}catch(Exception $e){
				$this->log('inpit Agent check redis :' . $e->getMessage(),'debug');
				$this->redis();
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
				$this->log('multi push error :' . $e->getMessage());
			}
			$this->begin = time(); //reset begin time
			unset($this->message); //reset message count
		}
	}

	private function indexerAgent(){
		$current_usage = memory_get_usage();
		$sync_second = $this->config['input_sync_second'];
		$sync_memory = $this->config['input_sync_memory'];
		$time = ($this->begin + $sync_second) - time() ;
		if((memory_get_usage() > $sync_memory) or ( $this->begin+$sync_second  < time())){
			$row = "";
			$ins = $this->esIndices();


			foreach($this->message as $pack){
				$json = json_decode($pack,true);
				$index = 'logstash-'.date('Y.m.d',strtotime($json['timestamp']));
				$this->esCurl('/'.$index,json_encode($ins),'PUT');
				exit;
				unset($json);
				$type = $this->config['list_key'];
				/*
				$row .= json_encode(['create' =>array_merge( [
					'_index' => 'logstash-'.date('Y-m-d'),
					'_type'  => $type,
				],$json)]) ."\n";
				*/
				$this->esCurl('/'.$index.'/'.$type.'',$pack,'post');
			}
			if(isset($row)){
				//$this->esCurl('/_bulk',$row,'post');
			}
			$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
				' current: '.$time.'s ','sync');
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

