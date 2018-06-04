<?php 
namespace Helloweba\Swoole;

use swoole_websocket_server;

class Chat
{
    protected $ws;
    protected $host = '0.0.0.0';
    protected $port = 9504;
    // 进程名称
    protected $taskName = 'swooleChat';
    // PID路径
    protected $pidFile = '/run/swooleChat.pid';
    // 设置运行时参数
    protected $options = [
        'worker_num' => 4, //worker进程数,一般设置为CPU数的1-4倍  
        'daemonize' => true, //启用守护进程
        'log_file' => '/data/logs/swoole.log', //指定swoole错误日志文件
        'log_level' => 0, //日志级别 范围是0-5，0-DEBUG，1-TRACE，2-INFO，3-NOTICE，4-WARNING，5-ERROR
        'dispatch_mode' => 1, //数据包分发策略,1-轮询模式
        //'task_worker_num' => 4, //task进程的数量
        //'task_ipc_mode' => 3, //使用消息队列通信，并设置为争抢模式
        //'heartbeat_idle_time' => 600, //一个连接如果600秒内未向服务器发送任何数据，此连接将被强制关闭
        //'heartbeat_check_interval' => 60, //启用心跳检测，每隔60s轮循一次
    ];
 
    // 安全密钥
    protected $safeKey = 'MYgGnQE33ytd2jDFADS39DSEWsdD24sK';

    protected $mmpic = [
        'http://pic15.photophoto.cn/20100402/0036036889148227_b.jpg',
        'http://pic23.nipic.com/20120814/5914324_155903179106_2.jpg',
        'http://pic40.nipic.com/20140403/8614226_162017444195_2.jpg'
    ];

    public function __construct($options = [])
    {
        $this->ws = new swoole_websocket_server($this->host, $this->port);

        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->ws->set($this->options);

        $this->ws->on("open", [$this, 'onOpen']);
        $this->ws->on("message", [$this, 'onMessage']);
        $this->ws->on("close", [$this, 'onClose']);
    }

    public function start()
    {
        // Run worker
        $this->ws->start();
    }

    public function onOpen(swoole_websocket_server $ws, $request)
    {
        // 设置进程名
        cli_set_process_title($this->taskName);
        //记录进程id,脚本实现自动重启
        $pid = "{$ws->master_pid}\n{$ws->manager_pid}";
        file_put_contents($this->pidFile, $pid);

        echo "server: handshake success with fd{$request->fd}\n";
    }

    public function onMessage(swoole_websocket_server $ws, $frame)
    {
        //$ws->push($frame->fd, "server-push:".date("Y-m-d H:i:s"));
        $connets = $ws->connections;
        echo count($connets)."\n";
        echo $frame->data. "\n";
        foreach ($ws->connections as $fd) {
            //$ws->push($fd, $this->reply($frame->data));
            //发送二进制数据：
            if ($frame->data == '图片') {
                $ws->push($fd, file_get_contents('https://www.helloweba.net/images/hellowebanet.png'), WEBSOCKET_OPCODE_BINARY);
            } elseif ($frame->data == '美女') {
                $mmpic = $this->mmpic;
                $picKey = array_rand($mmpic);
                $ws->push($fd, file_get_contents($mmpic[$picKey]), WEBSOCKET_OPCODE_BINARY);
            } else {
                $ws->push($fd, $this->reply($frame->data));
            }
            
        }
    }

    public function onClose($ws, $fid)
    {
        echo "client {$fid} closed\n";
        foreach ($ws->connections as $fd) {
            $ws->push($fd, $fid. '已离开！');
        }
    }

    private function reply($str) {
        $str = mb_strtolower($str);
        switch ($str) {
            case 'hello':
                $res = 'Hello, Friend.';
                break;

            case 'fuck':
                $res = 'Fuck bitch.';
                break;
            case 'ping':
                $res = 'PONG.';
                break;
            case 'time':
                $res = date('H:i:s');
                break;
            
            default:
                $res = $str;
                break;
        }
        return $res;
    }
}
