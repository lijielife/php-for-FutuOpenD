futuquant 3.0 (富途开放接口) PHP 接口

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
print_r($o->GetGlobalState());

异步模式参考 qot_push.php 启动: /path/to/php -f qot_push.php > /dev/null 2>&1 &
