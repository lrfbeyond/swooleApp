<?php 
namespace Helloweba\Swoole;

use swoole_server;
// use Helloweba\Swoole\Server;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    protected $serv;
    protected $host = '127.0.0.1';
    protected $port = 9502;
    // 进程名称
    protected $taskName = 'swooleMail';
    // PID路径
    protected $pidPath = '/run/swooleMail.pid';
    // 设置运行时参数
    protected $options = [
        'worker_num' => 4, //worker进程数,一般设置为CPU数的1-4倍  
        'daemonize' => true, //启用守护进程
        'log_file' => '/data/logs/swoole.log', //指定swoole错误日志文件
        'log_level' => 0, //日志级别 范围是0-5，0-DEBUG，1-TRACE，2-INFO，3-NOTICE，4-WARNING，5-ERROR
        'dispatch_mode' => 1, //数据包分发策略,1-轮询模式
        'task_worker_num' => 4, //task进程的数量
        'task_ipc_mode' => 3, //使用消息队列通信，并设置为争抢模式
    ];

    public function __construct($options = [])
    {
        // 构建Server对象，监听端口
        $this->serv = new swoole_server($this->host, $this->port);

        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->serv->set($this->options);
        

        // 注册事件
        $this->serv->on('Start', [$this, 'onStart']);
        $this->serv->on('Connect', [$this, 'onConnect']);
        $this->serv->on('Receive', [$this, 'onReceive']);
        $this->serv->on('Task', [$this, 'onTask']);  
        $this->serv->on('Finish', [$this, 'onFinish']);
        $this->serv->on('Close', [$this, 'onClose']);

        // 启动服务
        //$this->serv->start();
    }

    protected function init()
    {
        //
    }

    public function start()
    {
        // Run worker
        $this->serv->start();
    }

    public function onStart($serv)
    {
        // 设置进程名
        cli_set_process_title($this->taskName);
        //记录进程id,脚本实现自动重启
        $pid = "{$serv->master_pid}\n{$serv->manager_pid}";
        file_put_contents($this->pidPath, $pid);
    }

    //监听连接进入事件
    public function onConnect($serv, $fd, $from_id)
    {
        $serv->send( $fd, "Hello {$fd}!" );
    }

    // 监听数据接收事件
    public function onReceive(swoole_server $serv, $fd, $from_id, $data)
    {
        // echo "Get Message From Client {$fd}:{$data}\n";
        // $serv->send($fd, $data);
        $res['result'] = 'failed';
        $key = 'MYgGnQE2jDFADS39DSEWsdD2';

        $req = json_decode($data, true);
        $action = $req['action'];
        $token = $req['token'];
        $timestamp = $req['timestamp'];

        if (time() - $timestamp > 180) {
            $res['code'] = 4001;
            $serv->send($fd, json_encode($res));
        }

        $token_get = md5($action.$timestamp.$key);
        if ($token != $token_get) {
            $res['code'] = 1608;
            $serv->send($fd, json_encode($res));
        }

        switch ($action) {
            case 'reboot':  //重启
                //$serv->reload();
                break;
            case 'close':  //关闭
                //$serv->shutdown();
                break;
            default: 
                $res['result'] = 'success';
                $serv->send($fd, json_encode($res));
                $serv->task($data);  // 执行异步任务
                break;
        }

    }

    /**
    * @param $serv swoole_server swoole_server对象
    * @param $task_id int 任务id
    * @param $from_id int 投递任务的worker_id
    * @param $data string 投递的数据
    */
    public function onTask(swoole_server $serv, $task_id, $from_id, $data)
    {
        $res['result'] = 'failed';
        $req = json_decode($data, true);
        $action = $req['action'];
        //$serv->send($task_id, $action);
        echo date('Y-m-d H:i:s')." onTask: [".$action."].\n";
        switch ($action) {
            case 'sendmail': // 发送邮件
                $this->sendMailQueue();
                break;
            case 'test':
                include_once('conn.php');
                $i = 0;
                swoole_timer_tick(2000, function ($timer_id) use ($i) {
                    //global $i;
                    $sql = "SELECT * FROM `hw_mail` WHERE `is_delete`=1 ORDER BY id ASC LIMIT 1";
                    $this->writeLog('Run task.');
                    echo date('Y-m-d H:i:s') . "[".$i."] tick-2000ms".PHP_EOL;
                    $i++;
                    if ($i == 10) {
                        swoole_timer_clear($timer_id); //清除定时器
                    }
                });
                break;
            default:
                break;
        }
    }


    /**
    * @param $serv swoole_server swoole_server对象
    * @param $task_id int 任务id
    * @param $data string 任务返回的数据
    */
    public function onFinish(swoole_server $serv, $task_id, $data)
    {
        //
    }


    // 监听连接关闭事件
    public function onClose($serv, $fd, $from_id) {
        echo "Client {$fd} close connection\n";
    }

    public function stop()
    {
        $this->serv->stop();
    }

    // 邮件发送队列
    private function sendMailQueue()
    {
        $ini_arr = $this->parseIni('/etc/mail.ini');
        $mailer = $ini_arr['Mailer'];

        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
       
        $password = '123456x';
        $redis->auth($password);

        swoole_timer_tick(1000, function($timer) use ($redis, $mailer) { // 启用定时器，每1秒执行一次
            $value = $redis->lpop('mailerlist');
            if($value){
                $this->writeLog($value);
                $json = json_decode($value, true);
                $start = microtime(true);
                $rs = $this->sendMailer($mailer, $json);
                $end = microtime(true);
                if ($rs) {
                    echo '发送成功！耗时:'. round($end - $start, 3).'秒'.PHP_EOL;
                } else { // 把发送失败的加入到失败队列中，人工处理
                    $redis->rpush("mailerlist_err", $value);
                }
            }else{
                swoole_timer_clear($timer); // 停止定时器
                echo "Emaillist出队完成";
            }
        });
    }

    private function sendMailer($mailer, $data)
    {
        include_once('lib/email.php');
        $mail = new Email($mailer['smtp_server'], 25);
        $mail->setLogin($mailer['mail_user'], $mailer['mail_pass']);
        $mail->addTo($data['email'], ''); //receiver's name is optional
        $mail->setFrom($mailer['mail_user'], 'name'); //sender's name is optional
        $mail->setSubject($data['title']);
        $mail->setMessage($data['content'], true); //支持html
        $rs = $mail->send();
        return $rs;
    }
}
