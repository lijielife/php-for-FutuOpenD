FutuOpenD 3.0 (富途开放API) PHP 接口

已测试环境:
CentOS 7 + PHP 7 + Swoole 4

http://www.php.net/
https://www.centos.org/
http://pecl.php.net/package/swoole

富途网关启动:

https://futunnopen.github.io/futuquant/intro/intro.html
/path/to/FutuOpenD -cfg_file=/path/to/FutuOpenD.xml -hist_data_cfg_file=/path/to/FutuHistData.xml -console=0
/path/to/FutuHistData -hist_data_cfg_file=/path/to/FutuHistData.xml

同步模式:
$o = new futu($host, $port, $pass);
$o->market = 1; //港股行情
$o->trdEnv = 1; //真实交易环境
$o->trdMarket = 1; //港股交易
print_r($o->GetGlobalState());

同步加密模式:
$o = new futu($host, $port, $pass);
$o->encrypt = true; //通讯加密,同时FutuOpenD也要打开加密配置
$o->private_key = '/data/private.key'; //通讯密钥,与FutuOpenD配置的一致
$o->market = 1; //港股行情
$o->trdEnv = 1; //真实交易环境
$o->trdMarket = 1; //港股交易
print_r($o->GetGlobalState());

异步模式参考 qot_push.php 启动: /path/to/php -f qot_push.php > /dev/null 2>&1 &
