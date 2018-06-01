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
    protected $taskName = 'swooleMailer';
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
        //'heartbeat_idle_time' => 600, //一个连接如果600秒内未向服务器发送任何数据，此连接将被强制关闭
        //'heartbeat_check_interval' => 60, //启用心跳检测，每隔60s轮循一次
    ];
    // 邮件服务器配置
    protected $mailConfig = [
        'smtp_server' => 'smtp.163.com',
        'username' => 'example@163.com',
        'password' => '',// SMTP 密码/口令
        'secure' => 'ssl', //Enable TLS encryption, `ssl` also accepted
        'port' => 465, // tcp邮件服务器端口
    ];
    // 安全密钥
    protected $safeKey = 'MYgGnQE33ytd2jDFADS39DSEWsdD24sK';


    public function __construct($mailConfig, $options = [])
    {
        // 构建Server对象，监听端口
        $this->serv = new swoole_server($this->host, $this->port);

        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->serv->set($this->options);

        $this->mailConfig = $mailConfig;

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
        $serv->send($fd, "Hello {$fd}!" );
    }

    // 监听数据接收事件
    public function onReceive(swoole_server $serv, $fd, $from_id, $data)
    {
        $res['result'] = 'failed';
        $key = $this->safeKey;

        $req = json_decode($data, true);
        $action = $req['action'];
        $token = $req['token'];
        $timestamp = $req['timestamp'];

        if (time() - $timestamp > 180) {
            $res['code'] = '已超时';
            $serv->send($fd, json_encode($res));
            exit;
        }

        $token_get = md5($action.$timestamp.$key);
        if ($token != $token_get) {
            $res['msg'] = '非法提交';
            $serv->send($fd, json_encode($res));
            exit;
        }

        $res['result'] = 'success';
        $serv->send($fd, json_encode($res)); // 同步返回消息给客户端
        $serv->task($data);  // 执行异步任务

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
        echo date('Y-m-d H:i:s')." onTask: [".$action."].\n";
        switch ($action) {
            case 'sendMail': //发送单个邮件
                $mailData = [
                    'emailAddress' => '13966913@qq.com',
                    'subject' => 'swoole实验室',
                    'body' => '测试This is the HTML message body <b>in bold!</b>,<br/>欢迎访问<a href="https://www.helloweba.net/">www.helloweba.net</a>',
                    'attach' => '/home/swoole/public/a.jpg'
                ];
                $this->sendMail();
                break;
            case 'sendMailQueue': // 批量队列发送邮件
                $this->sendMailQueue();
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

    private function sendMail($mail_data = [])
    {
        $mail = new PHPMailer(true);
        try {
            $mailConfig = $this->mailConfig;
            //$mail->SMTPDebug = 2;        // 启用Debug
            $mail->isSMTP();   // Set mailer to use SMTP
            $mail->Host = $mailConfig['smtp_server'];  // SMTP服务
            $mail->SMTPAuth = true;                  // Enable SMTP authentication
            $mail->Username = $mailConfig['username'];    // SMTP 用户名
            $mail->Password = $mailConfig['password'];     // SMTP 密码/口令
            $mail->SMTPSecure = $mailConfig['secure'];     // Enable TLS encryption, `ssl` also accepted
            $mail->Port = $mailConfig['port'];    // TCP 端口

            $mail->CharSet  = "UTF-8"; //字符集
            $mail->Encoding = "base64"; //编码方式

            //Recipients
            $mail->setFrom($mailConfig['username'], 'Helloweba'); //发件人地址，名称
            $mail->addAddress($mail_data['emailAddress'], '亲');     // 收件人地址和名称
            //$mail->addCC('hellowebanet@163.com'); // 抄送

            //Attachments
            if (isset($mail_data['attach'])) {
                $mail->addAttachment($mail_data['attach']);         // 添加附件
            }
            
            //$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name

            //Content
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = $mail_data['subject'];
            $mail->Body    = $mail_data['body'];
            //$mail->AltBody = '这是在不支持HTML邮件客户端的纯文本格式';

            $mail->send();
            return true;
        } catch (\Exception $e) {
            echo 'Message could not be sent. Mailer Error: '. $mail->ErrorInfo;
            return false;
        }
    }

    // 邮件发送队列
    private function sendMailQueue()
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
       
        $password = '123456x';
        $redis->auth($password);

        swoole_timer_tick(1000, function($timer) use ($redis) { // 启用定时器，每1秒执行一次
            $value = $redis->lpop('mailerlist');
            if($value){
                //echo '获取redis数据:' . $value;
                $json = json_decode($value, true);
                $start = microtime(true);
                $rs = $this->sendMail($json);
                $end = microtime(true);
                if ($rs) {
                    echo '发送成功！'.$value.', 耗时:'. round($end - $start, 3).'秒'.PHP_EOL;
                } else { // 把发送失败的加入到失败队列中，人工处理
                    $redis->rpush("mailerlist_err", $value);
                }
            }else{
                swoole_timer_clear($timer); // 停止定时器
                echo "Emaillist出队完成";
            }
        });
    }
}
