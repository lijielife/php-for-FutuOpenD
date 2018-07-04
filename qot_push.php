<?php
if(php_sapi_name() !== 'cli'){
    die();
}

$host = '127.0.0.1';
$port = '10000';
$pass = '678910';

include("./class.futu.php");
$GLOBALS['futu'] = new futu($host, $port, $pass);

$cli = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
$cli->set(array(
	'socket_buffer_size' => 1024*1024*32, //32M缓存区
	'open_length_check'     => 1,
	'package_length_type'   => 'L',
	'package_length_offset' => 12,       //第N个字节是包长度的值
	'package_body_offset'   => 44,       //第几个字节开始计算长度
	'package_max_length'    => 8*1024*1024,  //协议最大长度
	'open_tcp_nodelay' => false
));

$GLOBALS['futu']->push($cli);

$cli->on('connect', function(swoole_client $cli){

    swoole_timer_tick(10000, function() use ($cli){ //每N秒执行
    	if(! $GLOBALS['futu']->connID){
    		return false;
    	}
    	$GLOBALS['futu']->KeepAlive(); //保持连接
    });
    
    swoole_timer_tick(5000, function() use ($cli){ //每N秒执行
        if(! $GLOBALS['futu']->InitConnect()){ //初始化连接
        	return false;
        }
        if(! $GLOBALS['futu']->Trd_UnlockTrade(true)){ //解锁交易
        	return false;
        }
        if(! $GLOBALS['futu']->Trd_GetAccList()){ //获取账户
        	return false;
        }
        if(! $GLOBALS['futu']->Trd_SubAccPush()){ //订阅订单推送
        	return false;
        }
		
		$GLOBALS['futu']->Qot_Sub('00700', 1, true, true, [1], false); //这里订阅...
        
    });
});
$cli->on('error', function(swoole_client $cli){
    exit(0);
});
$cli->on('receive', function(swoole_client $cli, $data){
    if(! $a = $GLOBALS['futu']->decode($data, '')){
		return array();
	}
	if(! $proto = (int)$a['proto']){
		return array();
	}

	if(! $a = $a['s2c']){
	    return array();
	}

	switch ($proto){
		case 1001: //初始化连接
			$GLOBALS['futu']->connID = (string)$a['connID'];
			$GLOBALS['futu']->loginUserID = (string)$a['loginUserID'];
		break;
		case 1004: //保持连接
		break;
		case 2001: //获取交易账号
			foreach ((array)$a['accList'] as $v){
				foreach ((array)$v['trdMarketAuthList'] as $vv){ //可拥有多个交易市场权限,目前仅单个
					$GLOBALS['futu']->accList[$vv][$v['trdEnv']] = (string)$v['accID'];
				}
			}
		break;
		case 2005: //解锁完成
			$GLOBALS['futu']->unlock = (bool)$a;
		break;
		case 2008: //订阅订单推送
			$GLOBALS['futu']->accPush = (bool)$a;
		break;
		case 2208: //推送订单更新
		break;
		case 2218: //推送新成交
		break;
		case 3001: //成功订阅K线
		break;
		case 3005: //推送股票基本报价
		break;
		case 3007: //推送K线
		break;
		case 3009: //推送分时
		break;
		case 3011: //推送逐笔
		break;
		case 3013: //推送买卖盘
		break;
		case 3015: //推送经纪队列
		break;
		default:
			
		break;
	}
});
$cli->on('BufferFull', function(swoole_client $cli){
    
});
$cli->on('BufferEmpty', function(swoole_client $cli){
    
});
$cli->on('close', function(swoole_client $cli){
    exit(0);
});
$cli->connect($host, $port, 0.1);