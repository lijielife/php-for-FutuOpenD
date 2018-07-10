<?php
/**
 * szargv@qq.com
 * $o = new futu($host, $port, $pass);
 * print_r($o->GetGlobalState());
 */
class futu{
	/**
	 * @var swoole_client
	 */
	private $cli = null;
	private $die = false;
	private $push = false; //是否推送模式
	
	private $host = '127.0.0.1';
	private $port = '100';
	private $pass = '';
	/**
	 * 请求的序列号
	 * @var integer
	 */
	private $sequence = 0;
	/**
	 * 心跳包定时器
	 * @var integer
	 */
	private $timer = 0;
	/**
	 * 同一个连接只解锁一次
	 * @var string
	 */
	public $unlock = false;
	/**
	 * 订单推送订阅
	 */
	public $accPush = false;
	/**
	 * 交易环境0正式1模拟
	 * @var integer
	 */
	public $TrdEnv = 0;
	/**
	 * 交易账号列表
	 * @var array
	 */
	public $accList = [];
	public $connID = 0;
	public $loginUserID = 0;

	/**
	 * @param string $host
	 * @param string $port
	 * @param string $pass
	 */
	public function __construct($host, $port, $pass=''){
		$this->host = $host;
		$this->port = $port;
		$this->pass = $pass;
		
		if(! class_exists('swoole_client')){ //强制使用
			die('This Lib Requires The SWOOLE Extention!');
		}
	}
	/**
	 * @param swoole_client $cli
	 * @return boolean
	 */
	public function push($cli){
		if(! $cli instanceof swoole_client){
			return false;
		}
		$this->cli = $cli;
		$this->push = true; //是否推送模式
	}
	private function Connect(){
		if(! $this->cli instanceof swoole_client){
			$this->timer = time(); //初始化心跳时间
			$this->cli = new swoole_client(preg_match('/^[0-9\.]+$/', $this->host)?(SWOOLE_SOCK_TCP|SWOOLE_KEEP):(SWOOLE_SOCK_UNIX_STREAM|SWOOLE_KEEP), SWOOLE_SOCK_SYNC);
			$this->cli->set(array(
					'socket_buffer_size' => 1024*1024*32, //32M缓存区
					'open_length_check'     => 1,
					'package_length_type'   => 'L',
					'package_length_offset' => 12,       //第N个字节是包长度的值
					'package_body_offset'   => 44,       //第几个字节开始计算长度
					'package_max_length'    => 8*1024*1024,  //协议最大长度
					'open_tcp_nodelay' => false
			));
			if(! @$this->cli->connect($this->host, $this->port, 8)){
				$this->errorlog('Connect Error.'.socket_strerror($this->cli->errCode), 0);
			}
		}
		if(! $this->cli){
			$this->errorlog('Client Error.', 0);
		}
		if($this->timer && (time() - $this->timer >= 20)){ //每N秒发一次心跳(只有同步模式会初始化时间)
			$this->timer = time(); //锁住
			$this->KeepAlive();
		}
		return $this->cli;
	}
	/**
	 * 关闭连接,销毁之前的数据
	 */
	private function close(){
		if($this->cli instanceof swoole_client){
			$this->cli->close(true);
		}
		
		$this->cli = null;
		$this->connID = 0;
		$this->unlock = false;
	}
	/**
	 * 初始化连接
	 * @return string
	 */
	public function InitConnect(){
		if($this->connID){ //已经初始化过
			return $this->connID;
		}
		$C2S = array(
				"clientVer" => 0,
				'clientID' => '0',
				'recvNotify' => true,
				);
		if(! $ret = $this->send('1001', $C2S)){
			return false;
		}

		$this->connID = (string)$ret['connID']; //uint64
		$this->loginUserID = (string)$ret['loginUserID']; //uint64

		return $this->connID;
	}
	/**
	 * 获取全局状态 
	 * @return array
	 */
	public function GetGlobalState(){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'userID' => (string)$this->loginUserID,
				);
		if(! $ret = $this->send('1002', $C2S)){
			return array();
		}
		return (array)$ret;
	}
	/**
	 * 保活心跳
	 * @return int
	 */
	public function KeepAlive(){
		if(! $this->InitConnect()){
			return 0;
		}
		$C2S = array(
				'time' => (int)time(),
		);
		if(! $ret = $this->send('1004', $C2S)){
			return 0;
		}
		return (int)$ret['time'];
	}
	/**
	 * 订阅或者反订阅,同时注册或者取消推送
	 * @param array $codes
	 * @param array $subTypeList 1报价;2摆盘;4逐笔;5分时;6日K;7五分K;8十五分K;9三十K;10六十K;11一分K;12周K;13月K;14经纪队列;15季K;16年K;17三分K
	 * @param bool $isSubOrUnSub true订阅false反订阅
	 * @param bool $isRegOrUnRegPush 是否注册或反注册该连接上面行情的推送,该参数不指定不做注册反注册操作
	 * @param array $regPushRehabTypeList 复权类型:0不复权1前复权2后复权
	 * @param bool $isFirstPush 注册后如果本地已有数据是否首推一次已存在数据
	 * @param number $market
	 * @return bool
	 */
	public function Qot_Sub($codes, $subTypeList, $isSubOrUnSub=true, $isRegOrUnRegPush=null, $regPushRehabTypeList=[], $isFirstPush=true, $market=1){
		if(! $this->InitConnect()){
			return false;
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return false;
		}
		$C2S = array(
				'securityList' => (array)$securityList,
				'subTypeList' => (array)$subTypeList, //订阅数据类型
				'isSubOrUnSub' => (bool)$isSubOrUnSub, //true订阅false反订阅
				'isFirstPush' => (bool)$isFirstPush,
		);
		if($isRegOrUnRegPush !== null){
			$C2S['isRegOrUnRegPush'] = (bool)$isRegOrUnRegPush;
		}
		if($isRegOrUnRegPush && $regPushRehabTypeList){
			$C2S['regPushRehabTypeList'] = (array)$regPushRehabTypeList;
		}
		if(! $ret = $this->send('3001', $C2S)){
			return false;
		}

		return (bool)$ret;
	}
	/**
	 * 注册行情推送(可用Qot_Sub替代)
	 * @param array $codes
	 * @param array $subTypeList 1报价;2摆盘;4逐笔;5分时;6日K;7五分K;8十五分K;9三十K;10六十K;11一分K;12周K;13月K;14经纪队列;15季K;16年K;17三分K
	 * @param bool $isRegOrUnReg
	 * @param array $rehabTypeList
	 * @param bool $isFirstPush
	 * @param int $market
	 * @return boolean
	 */
	public function Qot_RegQotPush($codes, $subTypeList, $isRegOrUnReg, $rehabTypeList=[1], $isFirstPush=true, $market=1){
		if(! $this->InitConnect()){
			return false;
		}
		if(! $this->Qot_Sub($codes, $subTypeList, true, $isRegOrUnReg, $rehabTypeList, $isFirstPush, $market)){
			return false;
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return false;
		}
		$C2S = array(
				'securityList' => (array)$securityList,
				'subTypeList' => (array)$subTypeList, //订阅数据类型
				'isRegOrUnReg' => (bool)$isRegOrUnReg, //true订阅false反订阅
				'isFirstPush' => (bool)$isFirstPush,
				'rehabTypeList' => (array)$rehabTypeList,
		);
		if(! $ret = $this->send('3002', $C2S)){
			return false;
		}
		
		return (bool)$ret;
	}
	/**
	 * 获取订阅信息
	 * @param bool $isReqAllConn 是否返回所有连接的订阅状态
	 */
	public function Qot_GetSubInfo($isReqAllConn){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'isReqAllConn' => (bool)$isReqAllConn,
		);
		if(! $ret = $this->send('3003', $C2S)){
			return array();
		}
		return (array)$ret;
	}
	/**
	 * 获取股票基本行情
	 * @param array $codes
	 * @param number $market
	 * @return array|array
	 */
	public function Qot_GetBasicQot($codes, $market=1){
		if(! $this->Qot_Sub($codes, [1], true)){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return array();
		}
		$C2S = array(
				'securityList' => (array)$securityList,
		);
		if(! $ret = $this->send('3004', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['basicQotList'] as $v){
			
			if(! $v['code'] = $v['security']['code']){
				continue;
			}
			$v['market'] = $v['security']['market'];
			unset($v['security']);
				
			$v['listTime'] = strtotime($v['listTime']);
			$v['updateTime'] = strtotime($v['updateTime']);

			$gets[$v['code']] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取K线
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $reqNum K线条数
	 * @param int $rehabType 复权类型:0不复权1前复权2后复权
	 * @param number $market
	 */
	public function Qot_GetKL($code, $klType, $reqNum=1000, $rehabType=1, $market=1){
		$map = array(
			1 => 11,
			2 => 6,
			3 => 12,
			4 => 13,
			5 => 16,
			6 => 7,
			7 => 8,
			8 => 9,
			9 => 10,
			10 => 17,
			11 => 15
		);
		if(! $this->Qot_Sub($code, [$map[$klType]], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				),
				'klType' => (int)$klType,
				'reqNum' => (int)$reqNum,
				'rehabType' => (int)$rehabType,				
		);
		if(! $ret = $this->send('3006', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['klList'] as $v){
			$v['time'] = strtotime($v['time']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取分时
	 * @param string $code
	 * @param int $market
	 * @return array|array
	 */
	public function Qot_GetRT($code, $market=1){
		if(! $this->Qot_Sub($code, [5], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				)
		);
		if(! $ret = $this->send('3008', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['rtList'] as $v){
			$v['time'] = strtotime($v['time']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取逐笔
	 * @param string $code
	 * @param int $maxRetNum
	 * @param int $market
	 * @return array|array
	 */
	public function Qot_GetTicker($code, $maxRetNum=1000, $market=1){
		if(! $this->Qot_Sub($code, [4], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				),
				'maxRetNum' => (int)$maxRetNum,
		);
		if(! $ret = $this->send('3010', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['tickerList'] as $v){
			$v['time'] = strtotime($v['time']);
			
			$gets[] = $v;
		}
		return (array)$gets;
		
	}
	/**
	 * 获取买卖盘
	 * @param string $code
	 * @param int $num
	 * @param int $market
	 * @return array|array[]
	 */
	public function Qot_GetOrderBook($code, $num=10, $market=1){
		if(! $this->Qot_Sub($code, [2], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				),
				'num' => (int)$num,
		);
		if(! $ret = $this->send('3012', $C2S)){
			return array();
		}

		return array('buy'=>(array)$ret['orderBookBidList'], 'sell'=>(array)$ret['orderBookAskList']);
	}
	/**
	 * 获取经纪队列
	 * @param string $code
	 * @param int $market
	 * @return array|array[]
	 */
	public function Qot_GetBroker($code, $market=1){
		if(! $this->Qot_Sub($code, [14], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				)
		);
		if(! $ret = $this->send('3014', $C2S)){
			return array();
		}
		return array((array)$ret['brokerAskList'], (array)$ret['brokerBidList']);
	}
	/**
	 * 获取板块集合下的板块(30秒10次)
	 * @param int $plateSetType 0所有版块1行业板块2地域板块3概念版块
	 * @param number $market
	 * @return array
	 */
	public function Qot_GetPlateSet($plateSetType, $market=1){
		if(! $this->limit(6, 30, 10, 1)){
			return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'plateSetType' => (int)$plateSetType,
				'market' => (int)$market,
		);
		if(! $ret = $this->send('3204', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['plateInfoList'] as $v){
			$gets[] = array('market'=>$v['plate']['market'],'code'=>$v['plate']['code'],'name'=>$v['name']);
		}
		return (array)$gets;
	}
	/**
	 * 获取板块下的股票(30秒10次)
	 * @param string $code 版块编号
	 * @param number $market
	 * @return array
	 */
	public function Qot_GetPlateSecurity($code, $market=1){
		if(! $this->limit(7, 30, 10, 1)){
			return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'plate' => array(
						'code' => (string)$code,
						'market' => (int)$market,
				),
		);
		if(! $ret = $this->send('3205', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['staticInfoList'] as $v){
			if($v['basic']){
				$v['basic']['code'] = $v['basic']['security']['code'];
				$v['basic']['market'] = $v['basic']['security']['market'];
				unset($v['basic']['security']);
				
				$v['basic']['listTime'] = strtotime($v['basic']['listTime']);
			}
			if($v['warrantExData']){
				$v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
				$v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
				unset($v['warrantExData']['owner']);
			}
			
			$a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
			if(! $a['code']){
				continue;
			}
			
			$gets[$a['code']] = $a;
		}
		return (array)$gets;
	}
	/**
	 * 获取单只股票一段历史K线
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $beginTime
	 * @param int $endTime
	 * @param int $maxAckKLNum 最多返回多少根K线,如果未指定表示不限制
	 * @param int $needKLFieldsFlag 指定返回K线结构体特定某几项数据,KLFields枚举值或组合,如果未指定返回全部字段
	 * @param int $rehabType 复权类型
	 * @param int $market
	 */
	public function Qot_GetHistoryKL($code, $klType, $beginTime, $endTime, $maxAckKLNum=0, $needKLFieldsFlag=[], $rehabType=1, $market=1){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => (int)$market,
						'code' => (string)$code,
				),
				'klType' => (int)$klType,
				'beginTime' => date("Y-m-d H:i:s", $beginTime),
				'endTime' => date("Y-m-d H:i:s", $endTime),
				'rehabType' => (int)$rehabType,
		);
		if($maxAckKLNum){
			$C2S['maxAckKLNum'] = (int)$maxAckKLNum;
		}
		if($needKLFieldsFlag){
			$C2S['needKLFieldsFlag'] = (array)$needKLFieldsFlag;
		}
		if(! $ret = $this->send('3100', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['klList'] as $v){
			$v['time'] = strtotime($v['time']);
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取多只股票多点历史K线,时间必须严格相等比如日K必须为该日的0点
	 * @param array $codes
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param array $timeList 时间戳数组,最多五个
	 * @param int $noDataMode 当请求时间点数据为空时:0返回空;1返回前一个时间点数据;2返回后一个时间点数据
	 * @param int $maxReqSecurityNum 最多返回多少只股票的数据
	 * @param array $needKLFieldsFlag
	 * @param number $rehabType
	 * @param number $market
	 * @return array|array
	 */
	public function Qot_GetHistoryKLPoints($codes, $klType, $timeList, $noDataMode=0, $maxReqSecurityNum=0, $needKLFieldsFlag=[], $rehabType=1, $market=1){
		if(! $this->InitConnect()){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return array();
		}
		$timeList = (array)$timeList;
		foreach ($timeList as $k => $v){
			$timeList[$k] = date('Y-m-d H:i:s', $v);
		}
		$C2S = array(
				'securityList' => (array)$securityList,
				'klType' => (int)$klType,
				'timeList' => (array)$timeList,
				'rehabType' => (int)$rehabType,
				'noDataMode' => (int)$noDataMode,
		);
		if($maxReqSecurityNum){
			$C2S['maxReqSecurityNum'] = (int)$maxReqSecurityNum;
		}
		if($needKLFieldsFlag){
			$C2S['needKLFieldsFlag'] = (array)$needKLFieldsFlag;
		}
		if(! $ret = $this->send('3101', $C2S)){
			return array();
		}
		return (array)$ret['klPointList'];
	}
	/**
	 * 获取复权信息
	 * @param array $codes
	 * @param int $market
	 * @return array|array
	 */
	public function Qot_GetRehab($codes, $market=1){
		if(! $this->InitConnect()){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return array();
		}
		$C2S = array(
				'securityList' => (array)$securityList,
		);
		if(! $ret = $this->send('3102', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['securityRehabList'] as $v){
			foreach ((array)$v['rehabList'] as $vv){
				$vv['time'] = strtotime($vv['time']);
				
				$gets[$v['security']['code']][] = $vv;
			}
		}
		return (array)$gets;
	}
	/**
	 * 获取交易日
	 * @param int $beginTime
	 * @param int $endTime
	 * @param int $market 0未知1港股2港期11美股12美期21沪股22深股
	 */
	public function Qot_GetTradeDate($beginTime, $endTime, $market=1){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'market' => (int)$market,
				'beginTime' => date('Y-m-d', $beginTime), //开始时间字符串
				'endTime' => date('Y-m-d', $endTime) //结束时间字符串
				);
		if(! $ret = $this->send('3200', $C2S)){
			return array();
		}
		$gets = array();
		foreach ($ret['tradeDateList'] as $v){
			$gets[] = strtotime($v['time']);
		}
		return (array)$gets;
	}
	/**
	 * 获取股票列表
	 * @param int $secType 0未知1债券2权证3正股4基金5涡轮6指数7板块8期权9板块集合
	 * @param int $market
	 * @return array
	 */
	public function Qot_GetStaticInfo($secType, $market=1){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'market' => (int)$market,
				'secType' => (int)$secType,
		);
		if(! $ret = $this->send('3202', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['staticInfoList'] as $v){
			if($v['basic']){
				$v['basic']['code'] = $v['basic']['security']['code'];
				$v['basic']['market'] = $v['basic']['security']['market'];
				unset($v['basic']['security']);
				
				$v['basic']['listTime'] = strtotime($v['basic']['listTime']);
			}
			if($v['warrantExData']){
				$v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
				$v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
				unset($v['warrantExData']['owner']);
			}
			
			$a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
			if(! $a['code']){
				continue;
			}
			
			$gets[$a['code']] = $a;
		}
		return (array)$gets;
	}
	/**
	 * 获取一批股票的快照信息,每次最多200支(30秒10次)
	 * @param array $codes
	 * @param number $market
	 * @return array|array
	 */
	public function Qot_GetSecuritySnapshot($codes, $market=1){
		if(! $this->limit(2, 30, 10, 1)){
			return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => (int)$market,
					'code' => (string)$code,
					);
		}
		if(! $securityList){
			return array();
		}
		$C2S = array(
				'securityList' => (array)$securityList,
		);
		if(! $ret = $this->send('3203', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['snapshotList'] as $v){
			if($v['basic']){
				$v['basic']['code'] = $v['basic']['security']['code'];
				$v['basic']['market'] = $v['basic']['security']['market'];
				unset($v['basic']['security']);
				
				$v['basic']['isSuspend'] = (int)$v['basic']['isSuspend'];
				$v['basic']['listTime'] = strtotime($v['basic']['listTime']);
				$v['basic']['updateTime'] = strtotime($v['basic']['updateTime']);
			}
			if($v['warrantExData']){
				$v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
				$v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
				unset($v['warrantExData']['owner']);
				
				$v['warrantExData']['maturityTime'] = strtotime($v['warrantExData']['maturityTime']);
				$v['warrantExData']['endTradeTime'] = strtotime($v['warrantExData']['endTradeTime']);
			}

			$a = array_merge((array)$v['basic'], (array)$v['equityExData'], (array)$v['warrantExData']);
			if(! $a['code']){
				continue;
			}
			
			$gets[$a['code']] = $a;
		}
		return (array)$gets;
	}
	/**
	 * 解锁交易(30秒10次)
	 * @param string $unlock true解锁false锁定
	 * @return bool
	 */
	public function Trd_UnlockTrade($unlock){
		if($this->unlock){
			return true;
		}
		if(! $this->limit(5, 30, 10, 1)){
			return false;
		}
		if(! $this->InitConnect()){
			return false;
		}

		$C2S = array(
				'unlock' => (bool)$unlock,
				'pwdMD5' => md5($this->pass),
		);
		if(! $ret = $this->send('2005', $C2S)){
			return false;
		}
		return $this->unlock = (bool)$ret;
	}
	/**
	 * 获取交易账户列表
	 * @return array
	 */
	public function Trd_GetAccList(){
		if($this->accList){
			return $this->accList;
		}
		if(! $this->InitConnect()){
			return array();
		}
		
		$C2S = array(
				'userID' => (string)$this->loginUserID,
		);
		if(! $ret = $this->send('2001', $C2S)){
			return array();
		}
		foreach ((array)$ret['accList'] as $v){
			foreach ((array)$v['trdMarketAuthList'] as $vv){ //可拥有多个交易市场权限,目前仅单个
				$this->accList[$vv][$v['trdEnv']] = (string)$v['accID']; 
			}
		}
		return (array)$this->accList;
	}
	/**
	 * 订阅接收交易账户的推送数据
	 * @param int $TrdEnv
	 * @param int $trdMarket
	 * @return array|array
	 */
	public function Trd_SubAccPush($TrdEnv=1, $trdMarket=1){
		if($this->accPush){
			return true;
		}
		if(! $this->Trd_UnlockTrade(true)){
			return false;
		}
		if(! $this->Trd_GetAccList()){
			return false;
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return false;
		}
		$C2S = array(
				'accIDList' => (array)$accID,
		);
		if(! $ret = $this->send('2008', $C2S)){
			return array();
		}
		
		return $this->accPush = (bool)$ret;
	}
	/**
	 * 获取历史订单列表(30秒10次)
	 * @param int $beginTime
	 * @param int $endTime
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中3提交失败4处理超时结果未知5已提交待成交10部分成交11全部成交12撤单剩余部分13撤单中14剩余部分撤单成功15全部已撤单21下单失败22已失效23已删除
	 * @param array $codeList 股票代码过滤['00700','00388']
	 * @param array $idList 订单ID过滤
	 * @param int $TrdEnv 0仿真环境1真实环境
	 * @param int $trdMarket 0未知1香港2美国3大陆4香港A股通
	 * @return array
	 */
	public function Trd_GetHistoryOrderList($beginTime, $endTime, $filterStatusList=[], $codeList=[], $idList=[], $TrdEnv=1, $trdMarket=1){
		if(! $this->limit(3, 30, 10, 1)){
			return array();
		}
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				),
				'filterConditions' => array(
						'beginTime' => date('Y-m-d H:i:s', $beginTime),
						'endTime' => date('Y-m-d H:i:s', $endTime),
				),
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList; 
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList; 
		}
		if($filterStatusList){
			$C2S['filterStatusList'] = (array)$filterStatusList; 
		}
		if(! $ret = $this->send('2221', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['orderList'] as $v){
			$v['createTime'] = strtotime($v['createTime']);
			$v['updateTime'] = strtotime($v['updateTime']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取历史成交列表(30秒10次)
	 * @param unknown $beginTime
	 * @param unknown $endTime
	 * @param array $codeList
	 * @param array $idList
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @return array|array
	 */
	public function Trd_GetHistoryOrderFillList($beginTime, $endTime, $codeList=[], $idList=[], $TrdEnv=1, $trdMarket=1){
		if(! $this->limit(4, 30, 10, 1)){
			return array();
		}
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				),
				'filterConditions' => array(
						'beginTime' => date('Y-m-d H:i:s', $beginTime),
						'endTime' => date('Y-m-d H:i:s', $endTime),
				),
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList;
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList;
		}
		if(! $ret = $this->send('2222', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['orderFillList'] as $v){
			$v['createTime'] = strtotime($v['createTime']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取持仓列表
	 * @param array $codeList
	 * @param array $idList
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @param number $filterPLRatioMin
	 * @param number $filterPLRatioMax
	 * @return array|array
	 */
	public function Trd_GetPositionList($codeList=[], $idList=[], $TrdEnv=1, $trdMarket=1, $filterPLRatioMin=0, $filterPLRatioMax=0){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				)
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList;
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList;
		}
		if($filterPLRatioMin){
			$C2S['filterPLRatioMin'] = (float)$filterPLRatioMin;
		}
		if($filterPLRatioMax){
			$C2S['filterPLRatioMax'] = (float)$filterPLRatioMax;
		}
		if(! $ret = $this->send('2102', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['positionList'] as $v){
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取订单列表
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中3提交失败4处理超时结果未知5已提交待成交10部分成交11全部成交12撤单剩余部分13撤单中14剩余部分撤单成功15全部已撤单21下单失败22已失效23已删除
	 * @param array $codeList
	 * @param array $idList
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @return array|array
	 */
	public function Trd_GetOrderList($filterStatusList=[], $codeList=[], $idList=[], $TrdEnv=1, $trdMarket=1){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				)
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList;
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList;
		}
		if($filterStatusList){
			$C2S['filterStatusList'] = (array)$filterStatusList;
		}
		if(! $ret = $this->send('2201', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['orderList'] as $v){
			$v['createTime'] = strtotime($v['createTime']);
			$v['updateTime'] = strtotime($v['updateTime']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 下单(30秒30次)
	 * @param string $code 股票代码
	 * @param int $trdSide 0未知1买入2卖出3卖空4买回
	 * @param float $qty
	 * @param float $price
	 * @param int $orderType 0未知1普通单2市价单(仅美股)5绝对限价订单6竞价订单7竞价限价订单8特别限价订单
	 * @param bool $adjustPrice 是否调整价格:如果挂单价格不合理是否调整到合理的档位
	 * @param unknown $adjustSideAndLimit 如果调整价格,是向上调整(正)还是向下调整(负),最多调整多少百分比
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @return id 订单ID
	 */
	public function Trd_PlaceOrder($code, $trdSide, $qty, $price, $orderType=1, $adjustPrice=false, $adjustSideAndLimit=0, $TrdEnv=1, $trdMarket=1){
		if(! $this->limit(10, 30, 30, 1)){
			return 0;
		}
		if(! $this->Trd_UnlockTrade(true)){
			return 0;
		}
		if(! $this->Trd_GetAccList()){
			return 0;
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return 0;
		}
		
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				),
				'packetID' => array(
						'connID' => (string)$this->connID,
						'serialNo' => (int)$this->sequence
				),
				'code' => (string)$code,
				'trdSide' => (int)$trdSide,
				'orderType' => (int)$orderType,
				'qty' => (float)$qty,
				'price' => (float)$price,
		);
		if($adjustPrice){
			$C2S['adjustPrice'] = (bool)$adjustPrice;
		}
		if($adjustSideAndLimit){
			$C2S['adjustSideAndLimit'] = (float)$adjustSideAndLimit;
		}
		if(! $ret = $this->send('2202', $C2S)){
			return 0;
		}
		return (string)$ret['orderID'];
	}
	/**
	 * 修改订单(改价/改量/改状态等)(30秒30次)
	 * @param string $orderID $forAll为true时传0
	 * @param int $modifyOrderOp 0未知1改单(价格/数量)2撤单3失效4生效5删除
	 * @param float $qty
	 * @param float $price
	 * @param bool $forAll
	 * @param bool $adjustPrice
	 * @param float $adjustSideAndLimit
	 * @param int $TrdEnv
	 * @param int $trdMarket
	 * @return number
	 */
	public function Trd_ModifyOrder($orderID, $modifyOrderOp, $qty=0, $price=0, $forAll=false, $adjustPrice=false, $adjustSideAndLimit=0, $TrdEnv=1, $trdMarket=1){
		if(! $this->limit(11, 30, 30, 1)){
			return 0;
		}
		if(! $this->Trd_UnlockTrade(true)){
			return 0;
		}
		if(! $this->Trd_GetAccList()){
			return 0;
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return 0;
		}
		
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				),
				'packetID' => array(
						'connID' => (string)$this->connID,
						'serialNo' => (int)$this->sequence
				),
				'orderID' => (string)$orderID,
				'modifyOrderOp' => (int)$modifyOrderOp,
				'forAll' => (bool)$forAll,
		);
		if($modifyOrderOp == 1){
			$C2S['qty'] = (float)$qty;
			$C2S['price'] = (float)$price;
		}
		if($adjustPrice){
			$C2S['adjustPrice'] = (bool)$adjustPrice;
		}
		if($adjustSideAndLimit){
			$C2S['adjustSideAndLimit'] = (float)$adjustSideAndLimit;
		}
		if(! $ret = $this->send('2205', $C2S)){
			return 0;
		}
		return (string)$ret['orderID'];
	}
	/**
	 * 获取账户资金
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @return array|array
	 */
	public function Trd_GetFunds($TrdEnv=1, $trdMarket=1){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
						),
				);
		if(! $ret = $this->send('2101', $C2S)){
			return array();
		}
		return (array)$ret['funds'];
	}
	/**
	 * 获取成交列表
	 * @param array $codeList
	 * @param array $idList
	 * @param number $TrdEnv
	 * @param number $trdMarket
	 * @return array|array
	 */
	public function Trd_GetOrderFillList($codeList=[], $idList=[], $TrdEnv=1, $trdMarket=1){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[(int)$trdMarket][(int)$TrdEnv]){
			return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => (int)$TrdEnv,
						'trdMarket' => (int)$trdMarket,
						'accID' => (string)$accID,
				)
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList;
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList;
		}
		if(! $ret = $this->send('2211', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['orderFillList'] as $v){
			$v['createTime'] = strtotime($v['createTime']);
			
			$gets[] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 编码
	 * @param int $proto
	 * @param string $C2S
	 * @return boolean|string
	 */
	public function encode($proto, $C2S){
		if(! $proto = (int)$proto){
			return false;
		}

		$ret = 'FT';
		$ret .= pack("L", (int)$proto); //协议ID
		$ret .= pack("C", 1); //协议格式类型，0为Protobuf格式，1为Json格式
		$ret .= pack("C", 0); //协议版本，用于迭代兼容
		$ret .= pack("L", $this->sequence = mt_rand(0, 4294967295)); //包序列号，用于对应请求包和回包
		$ret .= pack("L", strlen($C2S)); //包体长度
		$ret .= sha1($C2S, true); //包体原始数据(解密后)的SHA1哈希值
		$ret .= pack("@8");//保留8字节扩展
		$ret .= $C2S;
		
		return (string)$ret;
	}
	/**
	 * 解码回包
	 * @param string $recv
	 * @param string $C2S
	 * @return array
	 */
	public function decode($recv, $C2S){
		if(! $recv = trim($recv)){
			return array();
		}
		
		$pack = unpack("CF/CT/Lproto/CProtoFmtType/CProtoVer/LSerialNo/LBodyLen", $recv, 0);

		if(! $ret = json_decode(substr($recv, 44), true)){
			return array();
		}
		if($ret['retType'] != 0){
			$this->errorlog("ret Error:{$C2S} - {$ret['retType']}:{$ret['retMsg']}", in_array($ret['retType'], array(-1)) ? 1 : 0);
			return array();
		}
		if($ret['errCode'] != 0){
			$this->errorlog("err Error:{$C2S} - {$ret['errCode']}:{$ret['retMsg']}", 1);
			return array();
		}
		if($ret['retMsg']){
			$this->errorlog("ret Msg:{$C2S} - {$ret['retType']}:{$ret['retMsg']}", 4);
		}
	
		return array('proto'=>$pack['proto'], 's2c'=>$ret['s2c']?(array)$ret['s2c']:true);
	}
	/**
	 * 私有限额方法
	 * @param int $typ 限额类型:1**2快照3历史订单4历史成交5解锁6获取版块7版块下的股票10下单11改单
	 * @param int $sec 多少秒
	 * @param int $cnt 多少次 比如订单为30秒20次
	 * @param int $incr 累增多少
	 * @return boolean 是否在限额内
	 */
	private function limit($typ, $sec, $cnt, $incr=1){
		//做限额限频
		return true;
	}
	/**
	 *
	 * @param unknown $Protocol
	 * @param unknown $ReqParam
	 * @return array
	 */
	private function send($proto, $C2S){
		if(! $this->connect()){
			return array();
		}
		if(! $C2S = json_encode(array('c2s' => $C2S))){
			return array();
		}
		if(! $data = $this->encode($proto, $C2S)){
			return array();
		}
		
		if(! $length = @$this->cli->send("{$data}")){
			$this->errorlog("Send Error:{$C2S} - ".socket_strerror($this->cli->errCode), 3);
			return array();
		}
	
		if($this->push){ //推送模式不需要接收返回(此处必须返回空值)
			return array();
		}
		
		if(! $recv = @$this->cli->recv()){
			$this->errorlog("Recv Error:{$C2S} - ".socket_strerror($this->cli->errCode), 3);
			return array();
		}
		
		if(! $ret = $this->decode($recv, $C2S)){
			return array();
		}
		if($ret['proto'] && ($ret['proto'] != $proto) && ! in_array($proto, [3001,3002])){
			$this->errorlog("proto Error:{$C2S}", 1);
			return array();
		}
		
		return $ret['s2c'] ? (array)$ret['s2c'] : true;
	}
	/**
	 * 记录错误,加上断线自动重连
	 * @param unknown $msg
	 * @param number $level 0退出+日志1断线+日志2退出3断线4日志
	 * @return boolean
	 */
	private function errorlog($msg, $level=0){
		//记录错误
		return false;
	}
	public function __destruct(){
		
	}
}
