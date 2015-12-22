<?php
/**
 * Created by PhpStorm.
 * User: anythink
 * Date: 15/12/13
 * Time: 下午7:07
 */
define('php_logstash','1.0.2');
date_default_timezone_set('PRC');

class LogStash{
	private $config;
	private $redis;
	protected $message;
	private $begin;

	private $cmd;
	private $args;
	private $file_pointer=0;

	/**
	 * @param array $config
	 */
	function handler(array $cfg=[]){
		$key = ['listen::','indexer::','conf::','build::','status::'];
		$opt = getopt('',$key);
		$this->cmd = 'listen';
		foreach($key as $v){
			$v = str_replace('::','',$v);
			if(isset($opt[$v])){
				$this->cmd = $v;
				$this->args = $opt[$v];
				break;
			}
		}

		$this->default_value($cfg,'redis' ,'tcp://127.0.0.1:6379');
		$this->default_value($cfg,'agent_log',__DIR__ .'/agent.log');
		$this->default_value($cfg,'type','log');
		$this->default_value($cfg,'input_sync_memory',5*1024*1024);
		$this->default_value($cfg,'input_sync_second',5);
		$this->default_value($cfg,'parser',[$this,'parser']);
		$this->default_value($cfg,'log_level','product');

		$this->default_value($cfg,'elastic',['http://127.0.0.1:9200']);
		$this->default_value($cfg,'prefix','phplogstash');
		$this->default_value($cfg,'shards',5);
		$this->default_value($cfg,'replicas',1);
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
		$cfg = $this->getRedisHost();
		try {
			$redis = new redis();
			$redis->pconnect($cfg['host'],$cfg['port'],0);
			if($cfg['auth'])    $redis->auth($cfg['auth']);
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
		if($this->args){
			$this->log('begin in file mode' . $this->begin,'debug');
			if(!file_exists($this->args))exit(' file not found');

			while(true){
				$handle = fopen($this->args,'r');
				if ($handle){
					fseek($handle, $this->file_pointer);
					while($line = trim(fgets($handle))){
						$this->message[] = call_user_func($this->config['parser'],$line);
						$this->inputAgent();
					}
					$this->file_pointer = ftell($handle);
					fclose($handle);
					$this->log('listen for in ' . $this->args . ', file pointer ' . $this->file_pointer, 'debug');
					$this->inputAgent();
					sleep(1);
				}else{
					$this->file_pointer = 0;
				}
			}
		}else{
			$this->log('begin in tail mode ' . $this->begin,'debug');
			while($line = fgets(STDIN)){
				$line = trim($line);
				if($line){
					if($log = call_user_func($this->config['parser'],$line)){
						$this->message[] = $log;
					}
					$this->inputAgent();
				}
			}
		}
	}


	function indexer(){
		$this->begin = time();
		$this->esCurl('/_template/'.$this->config['prefix'],json_encode($this->esIndices()),'PUT');
		while (true) {
			while($msg = $this->redis->rPop($this->config['type'])){
				if (false !== $msg) {
					$this->message[] = $msg;
					$this->indexerAgent();
				}
			}
			sleep(1);
			$this->indexerAgent();
			$this->log('waiting for queue','debug');
		}
	}

	/**
	 * 获取队列信息
	 */
	function status(){
		$this->redis();
		$qps=0;
		while(true){
			echo $this->strPad('time') .'|'.
				$this->strPad('keys',10) .'|'.
				$this->strPad('QPS',10).'|'.
				PHP_EOL;

			$size = $this->redis->lSize($this->config['type']);
			if($qps == 0){
				$current_qps = 'N/A';
			}else{
				$current_qps  =  $qps - $size.'/s';
			}
			$qps = $size;
			echo $this->strPad(date('Y-m-d H:i:s'),20,' ') .'|'.
				$this->strPad($size,10,' ').'|'.
				$this->strPad($current_qps,10,' ').'|'.
				PHP_EOL;
			sleep(1);
		}
	}

	private function strPad($input,$len=20,$pad_str='='){
		return str_pad($input,$len,$pad_str,STR_PAD_BOTH);
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
			$s .= '{"timestamp":"'.date('c').'","host":"10.10.23.139","message":"'.$i.'","server":"v10.gaonengfun.com","client":"39.164.172.250","size":197,"responsetime":0.010,"domain":"v10.gaonengfun.com","method":"GET","url":"/index.php","requesturi":"/task/ballot?appkey=1&v=2.7.1&ch=xiaomi","via":"HTTP/1.1","request":"GET /task/ballot?appkey=1&v=2.7.1&ch=xiaomi HTTP/1.1","uagent":"NaoDong android 2.7.1","referer":"-","status":"200"}'.PHP_EOL;
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


	/**
	 * 获取redis配置
	 * @return array
	 */
	private function getRedisHost(){
		$cfg = $this->config['redis'];
		$parse_url = parse_url($cfg);
		return [
			'host' => $parse_url['host'],
			'port' => $parse_url['port'],
			'auth' => isset($parse_url['user']) ?  $parse_url['pass'] : null,
		];
	}

	/**
	 * 获取es配置
	 * @return array
	 */
	private function getEsHost(){
		if(is_array($this->config['elastic'])){
			$rand = mt_rand(0,count($this->config['elastic'])-1);
			$cfg = $this->config['elastic'][$rand];
		}else{
			$cfg = $this->config['elastic'];
		}
		$parse_url = parse_url($cfg);
		return [
			'url' => $parse_url['scheme'] .'://'. $parse_url['host'] .':'. $parse_url['port'],
			'user' => isset($parse_url['user']) ?  $parse_url['user'] : null,
			'pass' => isset($parse_url['pass']) ? $parse_url['pass'] : null,
		];
	}

	private function esCurl($url,$data='',$method='POST'){
		$cfg = $this->getEsHost();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $cfg['url'].$url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_TIMEOUT,300);
		//curl_setopt ($ch, CURLOPT_PROXY, 'http://192.168.1.40:8888');

		if($cfg['user'] || $cfg['pass']){
			curl_setopt($ch, CURLOPT_USERPWD, "{$cfg['user']}:{$cfg['pass']}");
		}

		$method = strtoupper($method);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if($data){
			curl_setopt ( $ch, CURLOPT_POSTFIELDS, $data );
		}


		$body = curl_exec($ch);
		$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);





		if(curl_error($ch) || $code > 201){
			$this->log('ElasticSearch error ' .PHP_EOL.
				'code: ' .$code. PHP_EOL.
				$cfg['url'] .$url .' '. $method .PHP_EOL.
				'Curl error:' . curl_error($ch) .PHP_EOL.
				'body: ' .$body .PHP_EOL.
				'data: '.mb_substr($data,0,400)
			);
		}

		curl_close($ch);
		unset($code,$err,$data);

		return json_decode($body,true);
	}

	private function esIndices(){
		$string_not_analyzed = ['type'=>'string','index'=>'not_analyzed','doc_values'=>true];
		//put /d curl -XPUT localhost:9200/_template/template_1 -d
		$indices['template'] = $this->config['prefix'].'-*';
		$indices['settings']['index'] = [
			'number_of_shards' => $this->config['shards'],
			'number_of_replicas' => $this->config['replicas'],
			'refresh_interval'=>'5s'
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['string_fields'] = [
			'match_mapping_type' => 'string',
			'mapping' => [
				'index' => 'analyzed',
				'omit_norms' => true,
				'type' => 'string',
				'fields' =>[
					'raw' => [
						'index' => 'not_analyzed',
						'ignore_above' => 256,
						'doc_values' => true,
						'type' => 'string'
					],
				],
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['float_fields'] = [
			'match_mapping_type' => 'float',
			'mapping' => [
				'doc_values' => true,
				'type' => 'float',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['double_fields'] = [
			'match_mapping_type' => 'double',
			'mapping' => [
				'doc_values' => true,
				'type' => 'double',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['byte_fields'] = [
			'match_mapping_type' => 'byte',
			'mapping' => [
				'doc_values' => true,
				'type' => 'byte',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['short_fields'] = [
			'match_mapping_type' => 'short',
			'mapping' => [
				'doc_values' => true,
				'type' => 'short',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['integer_fields'] = [
			'match_mapping_type' => 'integer',
			'mapping' => [
				'doc_values' => true,
				'type' => 'integer',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['long_fields'] = [
			'match_mapping_type' => 'long',
			'mapping' => [
				'doc_values' => true,
				'type' => 'long',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['date_fields'] = [
			'match_mapping_type' => 'date',
			'mapping' => [
				'doc_values' => true,
				'type' => 'date',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['geo_point_fields'] = [
			'match_mapping_type' => 'geo_point',
			'mapping' => [
				'doc_values' => true,
				'type' => 'geo_point',
			],
		];

		$indices['mappings']['_default_']['_all'] = [
			'enabled' => true,
			'omit_norms'=> true,
		];
		$indices['mappings']['_default_']['properties'] = [
			'timestamp' => ['type'=>'date','doc_values' => true],
			'client'    =>['type'=>'ip'],
			'host'		=> ['type'=>'string','index'=>'not_analyzed'],
			'size'      => ['type'=>'integer','doc_values'=>true],
			'responsetime' => ['type'=>'float','doc_values'=>true],
			'request' => ['type'=>'string'],
			'status' => ['type'=>'integer','doc_values'=>true],
			'args' => ['type'=>'object'],
		];
		return $indices;
	}

	/**
	 * 默认的处理log的方法
	 * @param $message
	 * @return mixed
	 */
	/**
	 * 默认的处理log的方法
	 * @param $message
	 * @return mixed
	 */
	private function parser($message){
		$message = str_replace(':-',':"-"',$message);
		$message = preg_replace('/\\\x[0-9a-f]{2}/i','?', $message);
		$json = json_decode($message,true);
		if($json['timestamp'] == ''){
			$this->log('empty timestamp log:'. $message);
			return false;
		}

		list($request_url,$params) = explode('?',$json['requesturi']);

		$client = explode(',',$json['client']);
		if(count($client) > 1){
			$json['client'] = array_shift($client);
		}elseif($json['client'] == '-'){
			$json['client'] = '127.0.0.1';
		}
		parse_str($params,$paramsOutput);
		$json['responsetime'] = floatval($json['responsetime']);
		$json['resquesturi'] = $request_url;
		$json['args'] = $paramsOutput;
		unset($request_url,$params,$paramsOutput,$client);
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
				$this->log('input Agent check redis :' . $e->getMessage(),'debug');
				$this->redis();
			}

			if(!empty($this->message)){
				try{
					$pipe = $this->redis->multi(Redis::PIPELINE);
					foreach($this->message as $pack){
						$pipe->lPush($this->config['type'],json_encode($pack));
					}
					$replies = $pipe->exec();
					$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
						' current: '.$time.'s ','sync');
				}catch (Exception $e){
					$this->log('multi push error :' . $e->getMessage());
				}
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
			$row = '';
			if(!empty($this->message)){
				foreach($this->message as $pack){
					$json = json_decode($pack,true);
					if(!$json['timestamp']) continue;
					$date = date('Y.m.d',strtotime($json['timestamp']));
					$type = $this->config['type'];
					$index = $this->config['prefix'].'-'.date('Y.m.d',strtotime($json['timestamp']));
					$row .= json_encode(['create' => ['_index' => $index ,'_type'  => $type]])."\n";
					$row .= json_encode($json)."\n";
				}
				if(!empty($row)){
					$this->esCurl('/_bulk',$row);
				}
			}


			$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
				' current: '.$time.'s ','elasticsearch');
			$this->begin = time(); //reset begin time
			unset($this->message,$current_usage,$sync_second,$sync_memory,$time); //reset message count
		}
	}

	private function default_value(&$arr,$k,$v = ''){
		return $arr[$k] = isset($arr[$k]) ? $arr[$k] : $v;
	}

	private function log($msg,$level='warning'){
		if($this->config['log_level'] == 'debug' || ($this->config['log_level'] != 'debug' and $level != 'debug')){
			$message = '['.$level.'] ['.date('Y-m-d H:i:s').'] ['.(memory_get_usage()/1024/1024).'MB] ' .$msg. PHP_EOL;
			file_put_contents($this->config['agent_log'], $message, FILE_APPEND);
		}
	}
}

