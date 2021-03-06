<?php

class WeixinAction extends Action
{
    private $token;
    private $fun;
    private $data = array();
    public $fans;
    private $my = '爱易';
    public $wxuser;
    public $apiServer;
    public $siteUrl;
    public $user;
    public $apikey;
    public $ali;//是否为支付宝
    public $babynumber;
    private $_appid;
    private $_secret;

    //应用密钥
    public function index()
    {
        //从请求中获取参数
        $this->_appid = C('site_appId');
        $this->_secret = C('site_appSecret');
        $this->siteUrl = C('site_url');
        $this->apikey = C('server_key');
        $this->token = $this->_get('token', 'htmlspecialchars');

        //验证token是否合法
        if (!preg_match('/^[0-9a-zA-Z]{3,42}$/', $this->token)) {
            die('error token');
        }

          //是否为支付宝
        $this->ali = 0;
        if (isset($_GET['ali']) && intval($_GET['ali'])) {
            $this->ali = 1;
        }else{
            $weixin = new Wechat($this->token, $this->wxuser);
        }

        //验证XML组件是否存存在
        if (!class_exists('SimpleXMLElement')) { die('SimpleXMLElement class not exist'); }
        if (!function_exists('dom_import_simplexml')) {die('dom_import_simplexml function not exist'); }


        //如果没有当前公众号的缓存则从数据库里查出来
        $this->wxuser = S('wxuser_'.$this->token);
        if (!$this->wxuser) {
            $this->wxuser = D('Wxuser')->where(array('token' => $this->token))->find();
            S('wxuser_' . $this->token, $this->wxuser);
        }

        //前台用户
       if(S('User_'.$this->wxuser['uid'])){
           $this->user=S('User_'.$this->wxuser['uid']);
       }else{
           $this->user = M('Users')->where(array('id' => $this->wxuser['uid']))->find();
           S('User_'.$this->wxuser['uid'],$this->user);
       }


        //返回经过XML解析的数组
        if (!$this->ali) {
            $this->data= $data = $weixin->request();
        }


        //公众号粉丝
        if (!S('fans_' . $this->token . '_' . $this->data['FromUserName'])) {
            $this->fans = M('Userinfo')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->find();
            S('fans_' . $this->token . '_' . $this->data['FromUserName'], $this->fans);
        }else{
           $this->fans=  S('fans_' . $this->token . '_' . $this->data['FromUserName']);
             }

        //定义机器人接口 现在是乐享的
        $this->my = C('site_my');
        $this->apiServer = apiServer::getServerUrl();

        //程序使用权限
        $open = M('Token_open')->where(array('token' => $this->_get('token')))->find();
        $this->fun = $open['queryname'];
        if (!$this->ali) {
            list($content, $type) = $this->reply($data);
            $weixin->response($content, $type);
        } else {
            $data = array();
            $data['Content'] = $this->_get('keyword');
            if (isset($_GET['eventType']) && $_GET['eventType']) {
                $data['Event'] = trim($this->_get('eventType'));
            }

            $data['FromUserName'] = $this->_get('fromUserName');
            $this->data = $data;
            echo json_encode($this->reply($data));
        }
    }

    //接收微信发送过来的XML后处理的主要方法，
    //包含所有的类型：如图片、事件等，还有一部分是通过上次的关键词缓存来进行相关处理
    //功能接口类操作：如附近等
    private function reply($data)
    {
        ////判断账号是否到期
        if ($this->user['viptime'] < time()) { return array('您的账号已经过期，请联系' . $this->siteUrl . '开通', 'text'); }

        //卡妞微秀
        $this->knwxs = S('knwxs_' . $this->token . '_' . $this->data['FromUserName']);
        if (!$this->knwxs) {
            $this->knwxs = M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->order('id desc')->find();
            S('knwxs_' . $this->token . '_' . $this->data['FromUserName'], $this->knwxs);
        }

        //微杂志
        $this->wzz = S('wzz_' . $this->token . '_' . $this->data['FromUserName']);
        if (!$this->wzz) {
            $this->wzz = M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->order('id desc')->find();
            S('wzz_' . $this->token . '_' . $this->data['FromUserName'], $this->wzz);
        }

         //语音功能调用传回来的语言识别结果
        if (isset($data['MsgType'])&&('voice' == $data['MsgType'])) {
                    $data['Content'] = $data['Recognition'];
                $this->data['Content'] = $data['Recognition'];
        }

        //爱美丽打印
        if ($this->wxuser['openphotoprint']) {
            $photoPrint = new photoPrint($this->wxuser, $this->data['FromUserName']);
        }

        //调用与事件对应的事件回复类中的index方法来处理当前的请求（如果这个类存在的话）
        $eventReplyClassName = $data['Event'] . 'EventReply';
        if (class_exists($eventReplyClassName)) {
            $eventReplyClassName = new $eventReplyClassName($this->token, $this->data['FromUserName'], $data, $this->siteUrl);
            return $eventReplyClassName->index();
        }

        if ('CLICK' == $data['Event']) {
            $data['Content'] = $data['EventKey'];
            $this->data['Content'] = $data['EventKey'];
        } elseif ($data['Event'] == 'SCAN') {
            $data['Content'] = $this->getRecognition($data['EventKey']);//二维码识别结果
            $this->data['Content'] = $data['Content'];
        } elseif ($data['Event'] == 'MASSSENDJOBFINISH') {
            M('Send_message')->where(array('msg_id' => $data['msg_id']))->save(array('reachcount' => $data['SentCount']));//群发信息结果
        } elseif ('subscribe' == $data['Event']) {//关注事件的处理
            $this->behaviordata('follow', '1');//记录这次事件
            $this->requestdata('follownum');//记录当前的公众号已经用的请求次数
            $follow_data = M('Areply')->field('home,keyword,content')->where(array('token' => $this->token))->find();

            //用户未关注时，进行关注后的事件推送 事件KEY值，qrscene_为前缀，后面为二维码的参数值
            if (strpos($data['EventKey'], 'qrscene_') ) {
                $follow_data['keyword'] = $this->getRecognition(str_replace('qrscene_', '', $data['EventKey']));
                $follow_data['home'] = 1;
            }

            //关注后回复图文的处理
            if ($follow_data['home'] == 1) {
                if (trim($follow_data['keyword']) == '首页' || $follow_data['keyword'] == 'home') {
                    return $this->shouye();
                } elseif (trim($follow_data['keyword']) == '我要上网') {
                    return $this->wysw();
                }else{
                    return $this->keyword($follow_data['keyword']);
                }

            } else {
                if ($follow_data['keyword'] != '') {
                    return $this->keyword($follow_data['keyword']);
                } else {
                       $greeting=$this->wxuser;
                      if ($this->wxuser['guanhuai']==1) {
                        //用户关怀
						$accessToken = $this->getAccessToken();
                        $b = $this->curlGet('https://api.weixin.qq.com/cgi-bin/user/info?access_token=' .$accessToken . '&openid=' . $this->data['FromUserName'] . '');
                        $jsonr = json_decode($b, 1);
                        $c = $this->curlGet('https://api.weixin.qq.com/cgi-bin/user/get?access_token=' . $accessToken . '');
                        $jsonr_a = json_decode($c, 1);
                        $jsonr['total'] = $jsonr_a['total'];
                          $greeting['url'] = str_replace('&amp;', '&', $greeting['url']);
                          $greeting['title1'] = str_replace('nickname', $jsonr['nickname'], $greeting['title1']);
                        //将文本内容里含有nickname的单词转换成变量
                          $greeting['title1'] = str_replace('openid', $jsonr['openid'], $greeting['title1']);
                          $greeting['title1'] = str_replace('city', $jsonr['city'], $greeting['title1']);
                          $greeting['title1'] = str_replace('province', $jsonr['province'], $greeting['title1']);
                          $greeting['title1'] = str_replace('country', $jsonr['country'], $greeting['title1']);
                          $greeting['title1'] = str_replace('total', $jsonr['total'], $greeting['title1']);
                        if ($jsonr['sex'] = '1') {
                            $greeting['title1'] = str_replace('sex', '男', $greeting['title1']);
                        } else {
                            $greeting['title1'] = str_replace('sex', '女', $greeting['title1']);
                        }
                        return array(array(array($greeting['title1'], strip_tags(htmlspecialchars_decode($greeting['text'])), $jsonr['headimgurl'], $greeting['url'])), 'news');
                    }
                    return array(html_entity_decode($follow_data['content']), 'text');
                }
            }
        } elseif ('unsubscribe' == $data['Event']) {
            $this->requestdata('unfollownum');
            // rippleos 需要对应终端重新认证
            $node = D('Rippleos_node')->where(array('token' => $this->token))->find();
            $this->rippleos_unauth($node['node']);
        } elseif ($data['Event'] == 'LOCATION') {
            return $this->nokeywordApi();
        }

			//信息传送
			$senmessage= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'statue'=>1))->find();
			$info= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],))->find();
			            if($senmessage ) {
							if($data['MsgType'] !== 'image')
							{$data['Content']=$data['Content'];}else{
								$data['Content'] = $this->data['PicUrl'];
								$da['type'] = '1';
              };

			

			$date['msg']=$data['Content'];
			$date['time']=time();
			$date['token']=$this->token;
			$date['wecha_id']=$this->data['FromUserName'];
			$date['user']=$info['name'];
			$date['head']=$info['headimgurl'];
			$date['from']='yonghu';
			$bb= M('queue_ansermsg')->add($date);
			$da['statue']=1;

			

			$bb= M('queue_ansermsg')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'statue'=>0))->save($da);
                            return array('您的回复我们已经收到，请您耐心等待，微乾隆将为您服务！', 'text');
				}
        //判断用户提交是否为图片

        if ($data['MsgType'] == 'image') {

            /**

             * 发送图片目前是晒图片的功能，

             */

            $pic_wall_inf = M('pic_wall')->where(array('token' => $this->token, 'status' => 1))->order('id desc')->find();
            $a = M('Userinfo')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->find();
            if ($pic_wall_inf && $pic_wall_inf['status'] === '1' && $a['wallopen'] == '0') {

                //存在晒照片活动并且 活动开关是开的

                /*--开始下载图片操作*/

                $sub_dir = date('Ymd');
                if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads') || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/uploads')) {
                    mkdir($_SERVER['DOCUMENT_ROOT'] . '/uploads', 511);
                }

                $firstLetterDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/picwall';
                if (!file_exists($firstLetterDir) || !is_dir($firstLetterDir)) {
                    mkdir($firstLetterDir, 511);
                }

                $firstLetterDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/picwall/' . $sub_dir;
                if (!file_exists($firstLetterDir) || !is_dir($firstLetterDir)) {
                    mkdir($firstLetterDir, 511);
                }

                $file_name = date('YmdHis') . '_' . rand(10000, 99999) . '.jpg';
                $pic_wall_save_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/picwall/' . $sub_dir . '/' . $file_name;
                $file_web_path = C('site_url') . '/uploads/picwall/' . $sub_dir . '/' . $file_name;
                $PicUrl = $data['PicUrl'];
                $imgdata = $this->curlGet($PicUrl);
                $fp = fopen($pic_wall_save_path, 'w');
                fwrite($fp, $imgdata);
                fclose($fp);

                //将照片的路径放入到 缓存中

                $checkresult = $pic_wall_inf['ischeck'] ? 0 : 1;

                //设置上墙图片的检查结果。如果活动设置 是需要审核，那么上墙结果为0需要审核，审核成功以后为1

                //插入到照片墙表中

                $pic_wall_log = array('uid' => $pic_wall_inf['id'], 'token' => $this->token, 'picurl' => $file_web_path, 'wecha_id' => $data['FromUserName'], 'create_time' => time(), 'username' => '', 'state' => $checkresult);
                S('zhaopianwall_' . $this->data['FromUserName'], $pic_wall_log, 60);

                //--下载图片结束
                return array('照片接收成功，请在一分钟内输入 上墙照片的显示名字，或者回复 取消 结束本次活动', 'text');
            }
        }

        /***微秀 制作完成后返回图文 链接**/

        if (strtolower($data['Content']) == 'over') {
            $ress = M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->select();
            if (!$ress) {
                return array('您还没开始做微秀！请回复“ok”开始制作。', 'text');
            }

            M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->save(array('knwxopen' => 0));
            $Knwxreplay = M('Knwxreplay')->where(array('token' => $this->token))->order('id desc')->find();
            $Kndata = M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 0))->order('id desc')->limit(1)->find();
            if (empty($Kndata['pic'])) {
                $Kndata['pic'] = C('site_url') . '/tpl/static/knwx/kn_deflaut.jpg';
            }

            S('knwxs_' . $this->token . '_' . $this->data['FromUserName'], NULL);
            return array(array(array($Knwxreplay['title'], $this->handleIntro($Knwxreplay['jianjie']), $Kndata['pic'], C('site_url') . '/index.php?g=Wap&m=Knwx&a=indexhi&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&catgroy=' . $Kndata['catgroy'] . '&id=' . $Kndata['id'] . '&sgssz=mp.weixin.qq.com')), 'news');
        }

        /*如果微信在制作过程中则每回复一个新增一行*/
        if ($this->knwxs['knwxopen']) {
            $thisItem = M('Knwxreplay')->where(array('token' => $this->token, 'isopen' => 1))->find();
            if (!$thisItem) {
                return array('卡妞微信模块没开启,如需退出，请输入“over”', 'text');
            } else {
                $thisknwx = M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->find();
                if ($thisknwx) {
                    $row = array();
                    if ('image' != $data['MsgType']) {
                        $row['content'] = str_replace('over', '', $data['Content']);
                        $row['title'] = '我的微秀';
                        $row['token'] = $this->token;
                        $row['wecha_id'] = $this->data['FromUserName'];
                        $row['time'] = time();
                        $row['knwxopen'] = 1;
                        $row['catgroy'] = $thisknwx['catgroy'];
                        $res = M('Knwxmy')->add($row);
                    } else {
                        $rows['pic'] = $data['PicUrl'];
                        $rows['title'] = '我的微秀';
                        $rows['token'] = $this->token;
                        $rows['wecha_id'] = $this->data['FromUserName'];
                        $rows['time'] = time();
                        $rows['knwxopen'] = 1;
                        $rows['catgroy'] = $thisknwx['catgroy'];
                        $res = M('Knwxmy')->add($rows);
                    }
                    if ($res) {
                        return array('继续回复微秀的内容，可使用文字、图片或照片，或者输入“over”完成制作', 'text');
                    } else {
                        return array('文字或者图片，写入失败,请回复“over”，再回复“ok”重新制作', 'text');
                    }
                }
            }
        }

        /***微秀**/

        /***微杂志**/

        if (strtolower($data['Content']) == 'end') {
            $ress = M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->select();
            if (!$ress) {
                return array('您还没开始做微杂志！请回复“wzz”开始制作。', 'text');
            }
            M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->save(array('knwxopen' => 0));
            $Knwxreplay = M('wzzreplay')->where(array('token' => $this->token))->order('id desc')->find();
            $Kndata = M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 0))->order('id desc')->limit(1)->find();
            if (empty($Kndata['pic'])) {
                $Kndata['pic'] = C('site_url') . '/tpl/static/knwx/kn_deflaut.jpg';
            }

            S('wzz_' . $this->token . '_' . $this->data['FromUserName'], NULL);
            return array(array(array($Knwxreplay['title'], $this->handleIntro($Knwxreplay['jianjie']), $Kndata['pic'], C('site_url') . '/index.php?g=Wap&m=Wzz&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&catgroy=' . $Kndata['catgroy'] . '&id=' . $kndata['id'] . '&sgssz=mp.weixin.qq.com')), 'news');
        }

        if ($this->wzz['knwxopen']) {
            $thisItem = M('wzzreplay')->where(array('token' => $this->token, 'isopen' => 1))->find();
            if (!$thisItem) {
                return array('卡妞微杂志模块没开启,如需退出，请输入“over”', 'text');
            } else {
                $thisknwx = M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->find();
                if ($thisknwx) {
                    $row = array();
                    if ('image' != $data['MsgType']) {
                        return array('只能回复图片', 'text');
                    } else {
                        $rows['pic'] = $data['PicUrl'];
                        $rows['title'] = '我的微杂志';
                        $rows['token'] = $this->token;
                        $rows['wecha_id'] = $this->data['FromUserName'];
                        $rows['time'] = time();
                        $rows['knwxopen'] = 1;
                        $rows['catgroy'] = $thisknwx['catgroy'];
                        $res = M('wzzmy')->add($rows);
                    }

                    if ($res) {
                        return array('继续回复微杂志的内容，发送图片或照片，或者输入“end”完成制作', 'text');
                    } else {
                        return array('图片写入失败,请回复“end”，再回复“ok”重新制作', 'text');
                    }
                }
            }
        }

        /***微杂志**/

        //判断照片墙

        $zhaopianwall_result = S('zhaopianwall_' . $data['FromUserName']);
        if ($zhaopianwall_result) {
            return $this->zhaopianwall($zhaopianwall_result);
        }

        if ($data['Content'] == 'wechat ip') {
            return array($_SERVER['REMOTE_ADDR'], 'text');
        }

        //判断是不是有API操作

        if (strpos($this->fun, 'api') && $data['Content']) {
            $apiData = M('Api')->where(array('token' => $this->token, 'status' => 1, 'noanswer' => 0))->select();
            foreach ($apiData as $apiArray) {
                if (!(strpos($data['Content'], $apiArray['keyword']) === FALSE)) {
                    $api = $apiArray;
                    break;
                }
            }

            if ($api != false) {
                $vo['fromUsername'] = $this->data['FromUserName'];
                $vo['Content'] = $this->data['Content'];
                $vo['toUsername'] = $this->token;
                $api['url'] = $this->getApiUrl($api['url'], $api['apitoken']);
                if ($api['type'] == 2) {
                    if (intval($api['is_colation'])) {
                        $vo['Content'] = trim(str_replace($api['keyword'], '', $vo['Content']));
                    }
                    $apidata = $this->api_notice_increment($api['url'], $vo, 0, 0);
                    return array($apidata, 'text');
                } else {
                    $xml = $GLOBALS['HTTP_RAW_POST_DATA'];
                    if($data['Content']){
                        $data['MsgType']='text';
                        unset($data['Event']);
                        unset($data['EventKey']);
                        $xml = new SimpleXMLElement('<xml></xml>');
                        $this->data2xml($xml, $data);
                        $xml=$xml->asXML();
                    }
                    if (intval($api['is_colation'])) {
                        $xml = str_replace(array($api['keyword'], $api['keyword'] . ' '), '', $xml);
                    }

                    $apidata = $this->api_notice_increment($api['url'], $xml, 0);
                    if ($apidata == false) {
                        return array('第三方接口返回错误', 'text');
                    }
                    header('Content-type: text/xml');
                    die($apidata);
                    return false;
                }
            }
        }


        if (strtolower($data['Content']) == 'wx#open') {
            M('Userinfo')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->save(array('wallopen' => 1));
            S('fans_' . $this->token . '_' . $this->data['FromUserName'], NULL);
            return array('您已进入微信墙对话模式，您下面发送的所有文字和图片信息都将会显示在大屏幕上，如需退出微信墙模式，请输入“quit”', 'text');

        } elseif (strtolower($data['Content']) == 'quit') {
            M('Userinfo')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->save(array('wallopen' => 0));
            S('fans_' . $this->token . '_' . $this->data['FromUserName'], NULL);
            return array('成功退出微信墙对话模式', 'text');
        }

        if ($this->fans['wallopen'] && !$this->knwxs['knwxopen']) {
            $where = array('token' => $this->token);
            $where['is_open'] = array('gt', 0);
            $thisItem = M('Wechat_scene')->where($where)->find();
            $acttype = 3;

            if (!$thisItem || !$thisItem['is_open']) {
                $thisItem = M('Wall')->where(array('token' => $this->token, 'isopen' => 1))->find();
                $acttype = 1;
            }

            if (!$thisItem) {
                return array('微信墙活动不存在,如需退出微信墙模式，请输入“quit”', 'text');
            } else {
                $memberRecord = M('Wall_member')->where(array('act_id' => $thisItem['id'], 'act_type' => $acttype, 'wecha_id' => $this->data['FromUserName']))->find();
                if (!$memberRecord) {
                    $this->data['Content'] = $thisItem['keyword'];
                    $data['Content'] = $thisItem['keyword'];
                } else {
                    $row = array();
                    if ('image' != $data['MsgType']) {
                        $message = str_replace('wx#', '', $data['Content']);
                    } else {
                        $message = '';
                        $row['picture'] = $data['PicUrl'];

                        $sub_dir = date('Ymd');
                        if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads') || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/uploads')) {
                            mkdir($_SERVER['DOCUMENT_ROOT'] . '/uploads', 511);
                        }

                        $firstLetterDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/wall';
                        if (!file_exists($firstLetterDir) || !is_dir($firstLetterDir)) {
                            mkdir($firstLetterDir, 511);
                        }

                        $firstLetterDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/wall/' . $sub_dir;
                        if (!file_exists($firstLetterDir) || !is_dir($firstLetterDir)) {
                            mkdir($firstLetterDir, 511);
                        }

                        $file_name = date('YmdHis') . '_' . rand(10000, 99999) . '.jpg';
                        $pic_wall_save_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/wall/' . $sub_dir . '/' . $file_name;
                        $file_web_path = C('site_url') . '/uploads/wall/' . $sub_dir . '/' . $file_name;
                        $PicUrl = $data['PicUrl'];
                        $imgdata = $this->curlGet($PicUrl);
                        $fp = fopen($pic_wall_save_path, 'w');
                        fwrite($fp, $imgdata);
                        fclose($fp);
                        $row['picture']=$file_web_path;
                    }

                    $row['uid'] = $memberRecord['id'];
                    $row['wecha_id'] = $this->data['FromUserName'];
                    $row['token'] = $this->token;
                    $thisWall = $thisItem;
                    $thisMember = $memberRecord;

                    if ($acttype == 1) {
                        $row['wallid'] = $thisItem['id'];
                        $needCheck = intval($thisWall['ck_msg']);
                    } else {
                        $row['wallid'] = intval($thisItem['wall_id']);
                        $includeWall = M('Wall')->where(array('id' => $row['wallid']))->find();
                        $needCheck = intval($includeWall['ck_msg']);
                    }

                    $row['content'] = $message;
                    $row['uid'] = $thisMember['id'];
                    $row['time'] = time();
                    $row['check_time'] = $row['time'];
                    if ($acttype == 3) {
                        $row['is_scene'] = '1';
                    } else {
                        $row['is_scene'] = '0';
                    }
                    $row['is_check'] = 1;
                    if ($needCheck) {
                        $row['is_check'] = 0;
                    }

                    M('Wall_message')->add($row);
                    $str = $this->wallStr($acttype, $thisItem);
                    return array($str, 'text');
                }
            }
        } else {
            if ('image' == $data['MsgType'] || 'video' == $data['MsgType']) {
                if ($this->wxuser['openphotoprint'] && 'image' == $data['MsgType']) {
                    return $photoPrint->uploadPic($data['PicUrl']);
                }

				$set= M('queue_set')->where(array('token'=>$this->token))->find();
                if (!$this->wxuser['openphotoprint'] && 'image' == $data['MsgType']&&$set['statue']==0) {
                    $apiwhere = array('token' => $this->token, 'status' => 1);
                    $apiwhere['noanswer'] = array('gt', 0);
                    $api = M('Api')->where($apiwhere)->find();
                    if (!$api) {
                        return array('该公众号未开启照片打印或微信墙活动', 'text');
                    }
                }
                return $this->nokeywordApi();
            }
        }

        //附近、公交、域名功能

        if (!(strpos($data['Content'], '附近') === FALSE)) {
            $this->recordLastRequest($data['Content']);
            $return = $this->fujin(array(str_replace('附近', '', $data['Content'])));
        } elseif (!(strpos($this->fun, 'gongjiao') === FALSE) && !(strpos($data['Content'], '公交') === FALSE) && strpos($data['Content'], '坐公交') === FALSE) {
            $return = $this->gongjiao(explode('公交', $data['Content']));
        } elseif (!(strpos($data['Content'], '域名') === FALSE)) {
            $return = $this->yuming(str_replace('域名', '', $data['Content']));
        } else {
            $check = $this->user('connectnum');
            if ($check['connectnum'] != 1) {
                if (C('connectout')) {
                    return array(C('connectout'), 'text');
                } else {
                    return array('请求量已用完', 'text');
                }
            }

            //取消关注时

            $Pin = new GetPin();
            $key = $data['Content'];
            $datafun = explode(',', $this->fun);
            $tags = $this->get_tags($key);//中文分词
            $back = explode(',', $tags);

            if (strtolower(substr($data['Content'], 0, 2)) == 'tp') {
                $key = '宝宝';
                $this->babynumber = substr($data['Content'], 2, 11);
            }

			//关闭工单系统

			 if ($data['Content'] == '领号') {
				 $lh=M('queue_set')->where(array('token'=>$this->token))->find();
                if($lh['statue']=='0'){
                return array($lh['zdhf'], 'text');
				}
            }

			//微乾隆工单评价系统

			$pjs= M('queue_evaluation')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'pj'=>0))->order('id desc')->limit(1)->find();
			if($pjs['pj']=='0'&&!empty($data['Content'])){
				 $pj['xx']=$data['Content'];
				  $pj['pj']=1;


			$pjs= M('queue_evaluation')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'pj'=>0))->save($pj);	
				 return array('非常感谢您的评价！微乾隆需要您的支持，你我共同发展！', 'text');
				}

			//微乾隆工单留言系统

			 $gongdan= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName']))->find();
			 $gongdans= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'liuyan_statue'=>0))->find();
			  $gongdanss= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'liuyan_statue'=>0,'znll'=>0))->find();
			 //智能分流

			 if($gongdans && $gongdans['znll']=='0') {
				 $znfl['znll']=$data['Content'];
				  $gongdanss= M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName'],'liuyan_statue'=>0,'znll'=>0))->save($znfl);
				  return array('已经给您分配到相应的客服！接着输入您要处理的主要内容【支持语音输入与图片上传】', 'text');

				 }

				 //end

			 if($gongdans &&!empty($data['Content'])){

				$date['liuyan']=$data['Content'];
				$date['liuyan_statue']=1;

				//return array($date['liuyan'], 'text');
				$gongdanenter=M('queue')->where(array('token' => $this->token,'wecha_id'=>$this->data['FromUserName']))->save($date);

				//消息推送
				$myopenid=M('queue_set')->where(array('token'=>$this->token))->find();

				$condition0['statue'] = 0;
                $condition0['token'] = $this->token;
				$condition1['statue'] = 1;
                $condition1['token'] = $this->token;
				$condition2['statue'] = '2';
                $condition2['token'] = $this->token;
				$myopenid=M('queue_set')->where(array('token'=>$this->token))->find();
				$count_0=M('queue')->where($condition0)->count();
				$count_1=M('queue')->where($condition1)->count();
				$count_2=M('queue')->where($condition2)->count();
				$username=M('queue')->where(array('token'=>$this->token,'wecha_id'=>$this->data['FromUserName'],'statue'=>0))->find();
				$znfl=M('queue_set')->where(array('token'=>$this->token))->find();

				//微乾隆写职能分流

					if($username['znll']=='1'){
						$myopenid['openid']=$znfl['openid'];
						$txt='{"touser":"'.$myopenid['openid'].'","template_id":"qLbPBZ_2WlnKWkAjjeQ_INcDwXI_inYXUkMWdHW6T80","url":"","topcolor":"#FF0033","data":{"first": {"value":"有新的用户进入排队，用户id【'.$username['id'].'】","color":"#173177"},"keyword1": {"value":"'.$data['Content'].'","color":"#FF0033"},"keyword2": {"value":"用户【'.$username['name'].'】的程序BUG问题","color":"#FF0033"},"remark": {"value":"目前还有【'.$count_0.'】人在排队中，受理中的有【'.$count_1.'】位，已经受理结束【'.$count_2.'】位","color":"#173177"}}}';
						}elseif($username['znll']=='2'){
							$myopenid['openid']=$znfl['czopenid'];
							$txt='{"touser":"'.$myopenid['openid'].'","template_id":"qLbPBZ_2WlnKWkAjjeQ_INcDwXI_inYXUkMWdHW6T80","url":"","topcolor":"#FF0033","data":{"first": {"value":"有新的用户进入排队,用户id【'.$username['id'].'】","color":"#173177"},"keyword1": {"value":"'.$data['Content'].'","color":"#FF0033"},"keyword2": {"value":"用户【'.$username['name'].'】的操作问题","color":"#FF0033"},"remark": {"value":"目前还有【'.$count_0.'】人在排队中，受理中的有【'.$count_1.'】位，已经受理结束【'.$count_2.'】位","color":"#173177"}}}';
							}else{
							$myopenid['openid']=$znfl['mqopenid'];
							$txt='{"touser":"'.$myopenid['openid'].'","template_id":"qLbPBZ_2WlnKWkAjjeQ_INcDwXI_inYXUkMWdHW6T80","url":"","topcolor":"#FF0033","data":{"first": {"value":"有新的用户进入排队,用户id【'.$username['id'].'】","color":"#173177"},"keyword1": {"value":"'.$data['Content'].'","color":"#FF0033"},"keyword2": {"value":"用户【'.$username['name'].'】的买前咨询","color":"#FF0033"},"remark": {"value":"目前还有【'.$count_0.'】人在排队中，受理中的有【'.$count_1.'】位，已经受理结束【'.$count_2.'】位","color":"#173177"}}}';
								}

				$accessToken = $this->getAccessToken();

					$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken.'';
				 $result=$this::https_post($url,$txt);

	//推送完毕			
				 return array('您反馈的内容我们已经收到，请您耐心等待，微乾隆将为您服务！回复【查询】可查询受理进度！', 'text');
				}



            if (strtolower(substr($data['Content'], 0, 2)) == 'mb') {
                $key = '萌宝';
                $this->babynumber = substr($data['Content'], 2, 11);
            }

            if ($key == '首页' || $key == 'home') {
                return $this->home();
            }

            foreach ($back as $keydata => $data) {
                $string = $Pin->Pinyin($data);
                if (in_array($string, $datafun) && $string) {
                    if ($string == 'fujin') {
                        $this->recordLastRequest($key);
                    }

                    $this->requestdata('textnum');//统计请求次数
                    unset($back[$keydata]);
                    $thirdApp = new thirdApp();
                    if (in_array($string, $thirdApp->modules())) {
                        $thirdAppmethod=new ReflectionMethod('thirdApp', $string);
                        $return=$thirdAppmethod->invoke(new thirdApp(),$back);
                    } elseif (method_exists('WeixinAction', $string)) {
                        $thirdAppMethod=new ReflectionMethod('WeixinAction',$string);
                        $return=$thirdAppMethod->invoke($this,$back);
                    } else {

                    }
                    break;
                }
            }
        }

        if (!empty($return)) {
            if (is_array($return)) {
                return $return;
            } else {
                return array($return, 'text');
            }

        } else {
            //抽奖作弊
            if (!(strpos($key, 'cheat') === FALSE)) {
                $arr = explode(' ', $key);
                $datas['lid'] = intval($arr[1]);
                $lotteryPassword = $arr[2];
                $datas['prizetype'] = intval($arr[3]);
                $datas['intro'] = $arr[4];
                $datas['wecha_id'] = $this->data['FromUserName'];
                $thisLottery = M('Lottery')->where(array('id' => $datas['lid']))->find();
                if ($lotteryPassword == $thisLottery['parssword']) {
                    $rt = M('Lottery_cheat')->add($datas);
                    if ($rt) {
                        return array('设置成功', 'text');
                   }
                    return array('设置失败:未知原因', 'text');
                } else {
                    return array('设置失败:密码不对', 'text');
               }
            }

            //发送位置
            if ($this->data['Location_X']) {
                //S('str',$this->data['Location_X']);
                //保存地理位置session，一分钟内不用重复发送
                $this->recordLastRequest($this->data['Location_Y'] . ',' . $this->data['Location_X'], 'location');
                return $this->map($this->data['Location_X'], $this->data['Location_Y']);
            }

            //获取公司路线图
            if (!(strpos($key, '开车去') === FALSE) || !(strpos($key, '坐公交') === FALSE) || !(strpos($key, '步行去') === FALSE)) {
                $this->recordLastRequest($key);
                //查询是否有一分钟内的经纬度
                $user_request_model = M('User_request');
                $loctionInfo = $user_request_model->where(array('token' => $this->_get('token'), 'msgtype' => 'location', 'uid' => $this->data['FromUserName']))->find();
                if ($loctionInfo && intval($loctionInfo['time'] > time() - 60)) {
                    $latLng = explode(',', $loctionInfo['keyword']);
                    return $this->map($latLng[1], $latLng[0]);
                }
                return array('请发送您所在的位置(对话框右下角点击＋号，然后点击“位置”)', 'text');
            }
            return $this->keyword($key);
        }

    }

    //相册

    private function xiangce()
    {
        $this->behaviordata('album', '', '1');
        $photo = M('Photo')->where(array('token' => $this->token, 'status' => 1))->find();
        $data['title'] = $photo['title'];
        $data['keyword'] = $photo['info'];
        $data['url'] = rtrim($this->siteUrl, '/') . U('Wap/Photo/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));
        $data['picurl'] = $photo['picurl'] ? $photo['picurl'] : rtrim($this->siteUrl, '/') . '/tpl/static/images/yj.jpg';
        return array(array(array($data['title'], $data['keyword'], $data['picurl'], $data['url'])), 'news');
    }

    //百度Map

    private function companyMap()
    {
        $mapAction = new Maps($this->token);
        return $mapAction->staticCompanyMap();
    }

    //审核

    private function shenhe($name)
    {
        $this->behaviordata('usernameCheck', '', '1');
        if (empty($name)) {
            return '正确的审核帐号方式是：审核+帐号';
        } else {
            $user = M('Users')->field('id')->where(array('username' => $name))->find();
            if ($user == false) {
                return $this->my . '提醒您,您还没注册吧 正确的审核帐号方式是：审核+帐号,不含+号';
            } else {
                $viptime = time() + intval(C('reg_validdays')) * 24 * 3600;
                $gid = C('reg_groupid');
                $up = M('users')->where(array('id' => $user['id']))->save(array('viptime' => $viptime, 'status' => 1, 'gid' => $gid, 'openid' => $this->data['FromUserName']));
                if ($up != false) {
                    return $this->my . '恭喜您,您的帐号已经审核,您现在可以登入微乾隆官网体验微乾隆强大的功能了!';
                } else {
                    return '服务器繁忙请稍后再试';
                }
            }
        }
    }

    //会员卡

    private function huiyuanka($name)
    {
        return $this->member();
    }

    private function member()
    {
        $card = M('member_card_create')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->find();
        $cardInfo = M('member_card_set')->where(array('token' => $this->token))->find();
        $this->behaviordata('Member_card_set', $cardInfo['id']);
        $reply_info_db = M('Reply_info');
        if ($card) {
            $where_member = array('token' => $this->token, 'infotype' => 'membercard');
            $memberConfig = $reply_info_db->where($where_member)->find();
            if (!$memberConfig) {
                $memberConfig = array();
                $memberConfig['picurl'] = rtrim($this->siteUrl, '/') . '/tpl/static/images/vip.jpg';
                $memberConfig['title'] = '省钱 打折 促销 优先知道';
                $memberConfig['info'] = '尊贵vip，是您消费身份的体现，省钱 打折 促销 优先知道';
            }
            $data['picurl'] = $memberConfig['picurl'];
            $data['title'] = $memberConfig['title'];
            $data['keyword'] = $memberConfig['info'];
           if (!$memberConfig['apiurl']) {
                $data['url'] = rtrim($this->siteUrl, '/') . U('Wap/Card/card', array('token' => $this->token, 'cardid' => $card['cardid'], 'wecha_id' => $this->data['FromUserName']));
            } else {
                $data['url'] = str_replace('{wechat_id}', $this->data['FromUserName'], $memberConfig['apiurl']);
            }

        } else {
            $where_unmember = array('token' => $this->token, 'infotype' => 'membercard_nouse');
            $unmemberConfig = $reply_info_db->where($where_unmember)->find();
            if (!$unmemberConfig) {
                $unmemberConfig = array();
                $unmemberConfig['picurl'] = rtrim($this->siteUrl, '/') . '/tpl/static/images/member.jpg';
                $unmemberConfig['title'] = '申请成为会员';
                $unmemberConfig['info'] = '申请成为会员，享受更多优惠';
            }
            $data['picurl'] = $unmemberConfig['picurl'];
            $data['title'] = $unmemberConfig['title'];
            $data['keyword'] = $unmemberConfig['info'];
            if (!$unmemberConfig['apiurl']) {
                $data['url'] = rtrim($this->siteUrl, '/') . U('Wap/Card/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));
            } else {
                $data['url'] = str_replace('{wechat_id}', $this->data['FromUserName'], $unmemberConfig['apiurl']);
           }

        }
        return array(array(array($data['title'], $data['keyword'], $data['picurl'], $data['url'])), 'news');
    }

    //淘宝 暂时应该用不了了

    private function taobao($name)
    {
        $name = array_merge($name);
        $data = M('Taobao')->where(array('token' => $this->token))->find();
        if ($data != false) {
            if (strpos($data['keyword'], $name)) {
                $url = $data['homeurl'] . '/search.htm?search=y&keyword=' . $name . '&lowPrice=&highPrice=';
            } else {
                $url = $data['homeurl'];
            }
            return array(array(array($data['title'], $data['keyword'], $data['picurl'], $url)), 'news');
        } else {
            return '商家还未及时更新淘宝店铺的信息,回复帮助,查看功能详情';
        }
    }

    //抽奖

    private function choujiang($name)
    {
        $data = M('lottery')->field('id,keyword,info,title,starpicurl')->where(array('token' => $this->token, 'status' => 1, 'type' => 1))->order('id desc')->find();
        if ($data == false) {
            return array('暂无抽奖活动', 'text');
        }
        $pic = $data['starpicurl'] ? $data['starpicurl'] : rtrim($this->siteUrl, '/') . '/tpl/User/default/common/images/img/activity-lottery-start.jpg';
        $url = rtrim($this->siteUrl, '/') . U('Wap/Lottery/index', array('type' => 1, 'token' => $this->token, 'id' => $data['id'], 'wecha_id' => $this->data['FromUserName']));
        return array(array(array($data['title'], $data['info'], $pic, $url)), 'news');
    }

    //关键词处理核心函数
    //包含系统关键词及自定义关键词
    private function keyword($key)
    {
        //系统自有的关键词
        switch ($key) {
            case 'ok':
                $knwx = M('Knwxreplay')->where(array('open' => '1', 'token' => $this->token))->find();
                if ($knwx == false) {
                    return array('目前卡妞微秀模块关闭了', 'text');
                }else{
                    $kndata['token'] = $this->token;
                    $kndata['wecha_id'] = $this->data['FromUserName'];
                    $kndata['knwxopen'] = 1;
                    $kndata['time'] = time();
                    $kndata['style'] = 1;
                    $kndata['title'] = '我的微秀';
                    $res = M('Knwxmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->find();
                    if ($res == false) {
                        $kndata['catgroy'] = time();
                        $re = M('Knwxmy')->add($kndata);
                        if ($re == false) {
                            return array('无法进入微秀制作模式', 'text');
                        }
                    }
                    if ($knwx) {
                        S('knwxs_' . $this->token . '_' . $this->data['FromUserName'], NULL);
                        return array('您已经进入了微秀制作模式,回复微秀的内容，可使用文字、图片或照片', 'text');
                    }
                }
                break;

            case '分享达人':
                $fconfig = M('sharetalent_reply')->where(array('token' => $this->token))->find();
                return array(array(array($fconfig['title'], str_replace('&nbsp;', '', strip_tags(htmlspecialchars_decode($fconfig['info']))), $fconfig['tp'], C('site_url') . '/index.php?g=Wap&m=Sharetalent&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                break;

            case '答题王':
                $pro = M('jikedati_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Jikedati&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['tp'], $url)), 'news');
                break;

            case '考试':

                $pro = M('fanyan_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Fanyan&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['tp'], $url)), 'news');
                break;

            case '微商盟':
                $pro = M('fenlei_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Fenlei&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['tp'], $url)), 'news');
                break;

            case '查询':

			    $pros_condition['statue'] = array('neq', 2);
                $pros_condition['token'] =$this->token;
				$pros_condition['wecha_id'] =$this->data['FromUserName'];
                $pros = M('queue')->where($pros_condition)->find();
                $conditions['time'] = array('elt', $pros['time']);
                $conditions['statue'] = array('neq', 2);
				$conditions['token'] = $this->token;
				$conditions['wecha_id'] = array('neq', $this->data['FromUserName']);
                $count_statue = M('queue')->where($conditions)->count();
				 $accessToken = $this->getAccessToken();
                if ($pros) {
                    if ($pros['statue'] == 2) {
                        $pros['dotime2'] = date('Y-m-d H:i:s', $pros['dotime2']);
						 $jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"xHBv8B3th2HrF_aFqO0lme3gMRS8F1Bkqs8TaGUoFn0","url":"","topcolor":"#FF0000","data":{"serviceInfo": {"value":"您好，您的工单已经处理完毕！","color":"#173177"},"serviceType": {"value":"微乾隆CMS工单处理","color":"#FF0033"},"serviceStatus": {"value":"工单处理完毕","color":"#FF0033"},"time": {"value":"'.$pros['time']=date('Y年m月d日 H时i分秒',$pros['time']).'","color":"#FF0033"},"remark": {"value":"您的工单已经处理完毕，感谢您对微乾隆一直的支持与信任！微乾隆与您同在！","color":"#173177"}}}';
						 $url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken.'';
               return       $result=$this::https_post($url,$jsonText);exit;
                    }
                    if ($pros['statue'] == 1) {
                        $pros['dotime1'] = date('Y-m-d H:i:s', $pros['dotime1']);
						$jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"xHBv8B3th2HrF_aFqO0lme3gMRS8F1Bkqs8TaGUoFn0","url":"","topcolor":"#FF0000","data":{"serviceInfo": {"value":"您好，您的工单正在受理中！","color":"#173177"},"serviceType": {"value":"微乾隆CMS工单处理","color":"#FF0033"},"serviceStatus": {"value":"受理中......","color":"#FF0033"},"time": {"value":"'.$pros['time']=date('Y年m月d日 H时i分秒',$pros['time']).'","color":"#FF0033"},"remark": {"value":"您的工单已经开始受理了，请您保持在线，及时回答客服的的回复！","color":"#173177"}}}';
						$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken.'';
						return   $result=$this::https_post($url,$jsonText);
                    }
                    //$pros['time']=date('Y-m-d H:i:s',$pros['time']);
					$jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0033","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$pros['number'].'号","color":"#FF0033"},"keyword3": {"value":"'.$count_statue.'位","color":"#FF0033"},"remark": {"value":"请您耐心等待！微乾隆与您同在","color":"#173177"}}}';
					$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken.'';
				  return  $result=$this::https_post($url,$jsonText);
                    exit;
                } else {
                    return array('尊敬的微乾隆商户:您还未领取排队号，请回复【领号】领取您的号码，微乾隆将在稍后为您处理相关问题', 'text');
                }

            case '领号':
                $pro = M('queue')->where(array('token' => $this->token))->find();
				$pros_condition['statue'] = array('neq', 2);
                $pros_condition['token'] =$this->token;
				$pros_condition['wecha_id'] =$this->data['FromUserName'];
                $pros = M('queue')->where($pros_condition)->find();
				$pross = M('queue')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->order('time desc')->limit(1)->find();
                $count = M('queue')->where(array('token' => $this->token))->count();
                $max_number = M('queue')->where(array('token' => $this->token))->order('number desc')->limit(1)->getField('number');
                $condition['statue'] = array('neq', 2);
                $condition['token'] = $this->token;
                $accessToken = $this->getAccessToken();

                $b = $this->curlGet('https://api.weixin.qq.com/cgi-bin/user/info?access_token='.$accessToken .'&openid=' . $this->data['FromUserName'] . '');

                $jsonr = json_decode($b, 1);

                if ($pross['statue'] == 2) {
				   $date['number'] = $max_number + 1;
                    $date['time'] = time();
                    $date['token'] = $this->token;
                    $date['wecha_id'] = $this->data['FromUserName'];
                    $date['headimgurl'] = $jsonr['headimgurl'];
                    $date['name'] = $jsonr['nickname'];
                    $date['sex'] = $jsonr['sex'];
                    $date['statue'] = 0;
					$date['znfl'] = 0;
                    //0 排队中  1 受理中 3 受理结束

                    $info = M('queue')->add($date);
					$condition0['statue'] = 0;
                $condition0['token'] = $this->token;
				$condition1['statue'] = 1;
                $condition1['token'] = $this->token;
				$condition2['statue'] = '2';
                $condition2['token'] = $this->token;
				$myopenid=M('queue_set')->where(array('token'=>$this->token))->find();
				$count_0=M('queue')->where($condition0)->count();
				$count_1=M('queue')->where($condition1)->count();
				$count_2=M('queue')->where($condition2)->count();
				$time_condition['token']=$this->token;
				$time_condition['wecha_id']=$this->data['FromUserName'];
				$time_condition['statue'] = array('neq', 2);
				$time2=M('queue')->where($time_condition)->find();
			    $conditionss['time'] = array('elt', $time2['time']);
                $conditionss['statue'] = array('neq', 2);
				$conditionss['token'] = $this->token;
				$conditionss['wecha_id'] = array('neq', $this->data['FromUserName']);
                $count_statue = M('queue')->where($conditionss)->count();

				//模版消息
				$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='. $accessToken.'';
				$jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0033","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$date['number'].'号","color":"#FF0033"},"keyword3": {"value":"'.$count_statue.'位","color":"#FF0033"},"remark": {"value":"请选择您要处理的内容。1：程序bug  2：操作问题 3：购买前咨询！","color":"#173177"}}}';

                return  $result=$this::https_post($url,$jsonText);
                exit;

				//end
                }
                if ($pros['statue'] == 1) {
                    $pros['dotime2'] = date('Y-m-d H:i:s', $pros['dotime2']);
                    return array('尊敬的微乾隆商户：【' . $pros['name'] . '】您好！您的工单正在处理中！受理时间：' . $pros['dotime2'] . '', 'text');
                }
                if (empty($pro)) {
                    $hao = '0';
                    $date['number'] = $hao + 1;
                    $date['time'] = time();
                    $date['headimgurl'] = $jsonr['headimgurl'];
                    $date['name'] = $jsonr['nickname'];
                    $date['sex'] = $jsonr['sex'];
                    $date['token'] = $this->token;
                    $date['wecha_id'] = $this->data['FromUserName'];
                    $date['statue'] = 0;
					

                    //0 排队中  1 受理中 3 受理结束

                    $info = M('queue')->add($date);
                } else {
                    if ($pros) {
                        $conditions['time'] = array('elt', $pros['time']);
                        $conditions['statue'] = array('neq', 2);
						$conditions['token'] = $this->token;
						$conditions['wecha_id'] = array('neq', $this->data['FromUserName']);
                        $count_statue = M('queue')->where($conditions)->count();
                        $pros['time'] = date('Y-m-d H:i:s', $pros['time']);
						$jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0000","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$pros['number'].'","color":"#FF0033"},"keyword3": {"value":"'.$count_statue.'位","color":"#FF0033"},"remark": {"value":"您已经领取过号码了，请勿重复领取！谢谢您的配合！","color":"#173177"}}}';
						$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken.'';
						$result=$this::https_post($url,$jsonText);exit;

                    }

                    $date['number'] = $max_number + 1;
                    $date['time'] = time();
                    $date['token'] = $this->token;
                    $date['wecha_id'] = $this->data['FromUserName'];
                    $date['headimgurl'] = $jsonr['headimgurl'];
                    $date['name'] = $jsonr['nickname'];
                    $date['sex'] = $jsonr['sex'];
                    $date['statue'] = 0;
					$date['znfl'] = 0;
                    //0 排队中  1 受理中 3 受理结束
                    $info = M('queue')->add($date);
                }

				//微乾隆点对点通知

				$condition0['statue'] = 0;
                $condition0['token'] = $this->token;
				$condition1['statue'] = 1;
                $condition1['token'] = $this->token;
				$condition2['statue'] = '2';
                $condition2['token'] = $this->token;
				$myopenid=M('queue_set')->where(array('token'=>$this->token))->find();
				$count_0=M('queue')->where($condition0)->count();
				$count_1=M('queue')->where($condition1)->count();
				$count_2=M('queue')->where($condition2)->count();
				$time_condition['token']=$this->token;
				$time_condition['wecha_id']=$this->data['FromUserName'];
				$time_condition['statue'] = array('neq', 2);
				$time2=M('queue')->where($time_condition)->find();
                $conditions['time'] = array('elt', $time2['time']);

                $conditions['statue'] = array('neq', 2);
				$conditions['token'] = $this->token;
				$conditions['wecha_id'] = array('neq', $this->data['FromUserName']);
                $count_statue = M('queue')->where($conditions)->count();
				//模版消息
				$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='. $accessToken.'';
				$jsonText='{"touser":"'.$this->data['FromUserName'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0000","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$date['number'].'号","color":"#FF0033"},"keyword3": {"value":"'.$count_statue.'位","color":"#FF0033"},"remark": {"value":"请选择您要处理的内容。1：程序bug  2：操作问题 3：购买前咨询","color":"#173177"}}}';
				$result=$this::https_post($url,$jsonText);exit;
				//end
                break;

            case '工单':
                $url = C('site_url') . '/index.php?g=Wap&m=Gongdan&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . ' &sgssz=mp.weixin.qq.com';
                $pic = C('site_url') . '/tpl/static/weiqianlong/wtongzhi.jpg';
                return array(array(array('工单微信通知', '点击进入获取您的专属微信通知OPENID', $pic, $url)), 'news');
                break;
            case '微信通知':
                $pro = M('fenlei_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Wtongzhi&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . ' &sgssz=mp.weixin.qq.com';
                $pic = C('site_url') . '/tpl/static/weiqianlong/wtongzhi.jpg';
                return array(array(array('微信通知', '点击进入获取您的专属微信通知OPENID', $pic, $url)), 'news');
                break;

            case '大数据':
                $pro = M('fenlei_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Kawahk&a=shuju&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array('贺卡数据分析', '爱易专业贺卡数据分析，您值得信赖！', C('site_url') . '/tpl/Wap/default/common/kawahk/shuju//shuju.jpg', $url)), 'news');
                break;

            case '微招聘':
                $pro = M('zhaopin_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Zhaopin&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                $news = array();
                array_push($news, array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['tp'], $url));
                array_push($news, array('【找简历】找简历，看这里', strip_tags(htmlspecialchars_decode($pro['info'])), C('site_url') . '/tpl/Wap/default/common/zhaopin/jianli.png', C('site_url') . '/index.php?g=Wap&m=Zhaopin&a=jlindex&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com'));
                array_push($news, array('【企业版】我要发布招聘', strip_tags(htmlspecialchars_decode($pro['info'])), C('site_url') . '/tpl/Wap/default/common/zhaopin/qiye.png', C('site_url') . '/index.php?g=Wap&m=Zhaopin&a=qiye&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com'));
                array_push($news, array('【个人版】我要发布简历', strip_tags(htmlspecialchars_decode($pro['info'])), C('site_url') . '/tpl/Wap/default/common/zhaopin/geren.png', C('site_url') . '/index.php?g=Wap&m=Zhaopin&a=geren&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com'));
                return array($news, 'news');
                break;

            case '找房子':
                $pro = M('Fangchan_reply')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Fangchan&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                $news = array();
                array_push($news, array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['tp'], $url));
                array_push($news, array('点此→免费发布房源信息', strip_tags(htmlspecialchars_decode($pro['info'])), C('site_url') . '/tpl/Wap/default/common/zhaopin/geren.png', C('site_url') . '/index.php?g=Wap&m=Fangchan&a=fabu&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com'));
                return array($news, 'news');
                break;

            case '主题活动':
                $pro = M('Baoming')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Baoming&a=lists&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['jianjie'])), $pro['tp'], $url)), 'news');
                break;

            case '我的微秀':
                $pro = M('knwxreplay')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Knwx&a=history&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array('我的微秀', '查看我的微秀记录，回复【ok】可继续制作贺卡', $pro['mypicurl1'], $url)), 'news');
                break;

            case '宝宝':
                $this->requestdata('other');
                $babynumber = trim($this->babynumber);
                $babynumber = (int) $babynumber;
                $vote = M('Hvote')->where(array('token' => $this->token))->order('id desc')->find();
                if ($vote == false) {
                   return array('目前没有投票活动活动或者投票已经结束', 'text');
                } else {
                    if (time() < $vote['statdate']) {
                        return array('投票活动还没开始', 'text');
                    } else {
                       if (time() > $vote['enddate']) {
                          return array('投票活动已经结束', 'text');
                        }
                    }
                }

                $v_item = M('Hvote_item');
                $item = $v_item->where(array('vid' => $vote['id'], 'checks' => 1, 'rank' => $babynumber))->find();
               if ($item == false) {
                   return array('您投票的对象不存在或者没审核通过,请认真查看投票规则：回复 tp+编号，例如：tp7', 'text');
                }
                $v_record = M('Hvote_record');
                $url = C('site_url') . U('Wap/Hvote/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));
                $today = strtotime(date('Y-m-d 00:00:00'));
                $datacorder['touch_time'] = array('egt', $today);
                $datacorder['vid'] = $vote['id'];
                $datacorder['rank'] = $babynumber;
                $datacorder['token'] = $this->token;
                $datacorder['wecha_id'] = $this->data['FromUserName'];
                $count = $v_record->where($datacorder)->count();
                $datacorder2['touch_time'] = array('egt', $today);
                $datacorder2['vid'] = $vote['id'];
                $datacorder2['token'] = $this->token;
                $datacorder2['wecha_id'] = $this->data['FromUserName'];
                $count2 = $v_record->where($datacorder2)->count();
                $datacorder3['vid'] = $vote['id'];
                $datacorder3['token'] = $this->token;
                $datacorder3['wecha_id'] = $this->data['FromUserName'];
                $count3 = $v_record->where($datacorder3)->count();
                if ($count3 >= $vote['gz3']) {
                    return array('您只能投' . $vote['gz3'] . '票,下次再来吧!', 'text');
               } else {
                   if ($vote['gz2'] > 0 && $count >= $vote['gz2']) {
                       return array('每个选项一天只能投' . $vote['gz2'] . '票,下次再来吧!', 'text');
                    } else {
                        if ($vote['gz1'] > 0 && $count2 >= $vote['gz1']) {
                            return array('一天只能投' . $vote['gz'] . '票,下次再来吧!', 'text');
                        }
                    }
                }
                $data = array('item_id' => $item['id'], 'rank' => $babynumber, 'token' => $this->token, 'vid' => $vote['id'], 'wecha_id' => $this->data['FromUserName'], 'touch_time' => time(), 'touched' => 1);
                $ok = $v_record->add($data);
                if ($ok == false) {
                    return array($vote['sb'], 'text');
                } else {
                   $res = $v_item->where(array('rank' => $babynumber, 'vid' => $vote['id']))->setInc('vcount', 1);
                    if ($res) {
                        $Lotterys = M('Lottery')->where(array('token' => $this->token, 'is_toupiao' => 1))->select();
                        foreach ($Lotterys as $key => $val) {
                            if ($val['type'] == 1) {
                                $model = 'Lottery';
                            } else {
                                if ($val['type'] == 2) {
                                    $model = 'Guajiang';
                                } else {
                                    if ($val['type'] == 3) {
                                        $model = 'Coupon';
                                    } else {
                                        if ($val['type'] == 4) {
                                            $model = 'LuckyFruit';
                                        } else {
                                            if ($val['type'] == 5) {

                                                $model = 'GoldenEgg';

                                            } else {

                                                if ($val['type'] == 6) {

                                                    $model = 'Shakeprize';

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $return[] = array($vote['cg'] . '我是' . $item['rank'] . '号' . $item['item'], '亲，发送tp' . $item['rank'] . '可以为我投票哟！', $item['startpicurl'], C('site_url') . '/index.php?g=Wap&m=Hvote&a=item_view&vid=' . $item['id'] . '&id=' . $vote['id'] . '&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com');
                        return array($return, 'news');
                    }
                }
               break;

            case '萌宝':
                $babynumber = trim($this->babynumber);
                $babynumber = (int) $babynumber;
                $vote = M('Hvotes')->where(array('token' => $this->token))->order('id desc')->find();
                if ($vote == false) {
                    return array('目前没有投票活动活动或者投票已经结束', 'text');
                } else {
                    if (time() < $vote['statdate']) {
                        return array('投票活动还没开始', 'text');
                    } else {
                        if (time() > $vote['enddate']) {
                            return array('投票活动已经结束', 'text');
                        }
                    }
                }

                $v_item = M('Hvotes_item');

                $item = $v_item->where(array('vid' => $vote['id'], 'checks' => 1, 'rank' => $babynumber))->find();

                if ($item == false) {

                    return array('您投票的对象不存在或者没审核通过,请认真查看投票规则：回复 tp+编号，例如：tp7', 'text');

                }

                $v_record = M('Hvotes_record');

                $url = C('site_url') . U('Wap/Hvotes/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));

                $today = strtotime(date('Y-m-d 00:00:00'));

                $datacorder['touch_time'] = array('egt', $today);

                $datacorder['vid'] = $vote['id'];

                $datacorder['rank'] = $babynumber;

                $datacorder['token'] = $this->token;

                $datacorder['wecha_id'] = $this->data['FromUserName'];

                $count = $v_record->where($datacorder)->count();

                $datacorder2['touch_time'] = array('egt', $today);

                $datacorder2['vid'] = $vote['id'];

                $datacorder2['token'] = $this->token;

                $datacorder2['wecha_id'] = $this->data['FromUserName'];

                $count2 = $v_record->where($datacorder2)->count();

                if ($vote['gz2'] > 0 && $count >= $vote['gz2']) {

                    return array('每个选项一天只能投' . $vote['gz2'] . '票,明天再来吧!', 'text');

                }

                if ($vote['gz1'] > 0 && $count2 >= $vote['gz1']) {

                    return array('一天只能投' . $vote['gz'] . '票,明天再来吧!', 'text');

                }

                $data = array('item_id' => $item['id'], 'rank' => $babynumber, 'token' => $this->token, 'vid' => $vote['id'], 'wecha_id' => $this->data['FromUserName'], 'touch_time' => time(), 'touched' => 1);

                $ok = $v_record->add($data);

                if ($ok == false) {

                    return array($vote['sb'], 'text');

                } else {

                    $res = $v_item->where(array('rank' => $babynumber, 'vid' => $vote['id']))->setInc('vcount', 1);

                    if ($res) {

                        return array(array(array($vote['cg'] . '我是' . $item['rank'] . '号' . $item['item'], '亲，发送mb' . $item['rank'] . '可以为我投票哟！', $item['startpicurl'], C('site_url') . '/index.php?g=Wap&m=Hvotes&a=item_view&vid=' . $item['id'] . '&id=' . $vote['id'] . '&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com')), 'news');

                    }

                }

                break;

            case '我的微杂志':

                $pro = M('wzzreplay')->where(array('token' => $this->token))->find();
                $url = C('site_url') . '/index.php?g=Wap&m=Wzz&a=history&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com';
                return array(array(array('我的微杂志', '查看我的微杂志记录，回复【wzz】可继续制作微杂志', rtrim($this->siteUrl, '/') . '/tpl/static/knwx/wzz.jpg', $url)), 'news');
                break;


            case '首页':

            case 'home':

            case 'Home':
                return $this->home();
                break;

            case '主页':

                return $this->home();
                break;

            case '地图':
                return $this->companyMap();

            case '最近的':
                $this->recordLastRequest($key);
                //查询是否有一分钟内的经纬度
                $user_request_model = M('User_request');
                $loctionInfo = $user_request_model->where(array('token' => $this->_get('token'), 'msgtype' => 'location', 'uid' => $this->data['FromUserName']))->find();
                if ($loctionInfo && intval($loctionInfo['time'] > time() - 60)) {
                    $latLng = explode(',', $loctionInfo['keyword']);
                    return $this->map($latLng[1], $latLng[0]);
                }
                return array('请发送您所在的位置(对话框右下角点击＋号，然后点击“位置”)', 'text');
                break;

            case '帮助':
                return $this->help();
                break;

            case 'help':
               return $this->help();
                break;

            case '会员卡':
                return $this->member();
                break;

            case '会员':
                return $this->member();
                break;

            case '3g相册':
                return $this->xiangce();
                break;

            case '相册':
                return $this->xiangce();
                break;
            case '商城':
                $pro = M('reply_info')->where(array('infotype' => 'Shop', 'token' => $this->token))->find();
                $url = $this->siteUrl . '/index.php?g=Wap&m=Store&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '';
                if ($pro['apiurl']) {
                    $url = str_replace('&amp;', '&', $pro['apiurl']);
                }

                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $url)), 'news');

                break;

            case '订餐':
                $pro = M('reply_info')->where(array('infotype' => 'Dining', 'token' => $this->token))->find();
                $url = $this->siteUrl . '/index.php?g=Wap&m=Repast&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '';
                 if ($pro['apiurl']) {
                    $url = str_replace('&amp;', '&', $pro['apiurl']);
                }
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $url)), 'news');
                break;

            case '留言':
                $pro = M('reply_info')->where(array('infotype' => 'message', 'token' => $this->token))->find();
               if ($pro) {
                   return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Reply&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                } else {
                    return array(array(array('留言板', '在线留言', rtrim($this->siteUrl, '/') . '/tpl/Wap/default/common/css/style/images/ly.jpg', $this->siteUrl . '/index.php?g=Wap&m=Reply&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                }

                break;

            case '酒店':
                $pro = M('reply_info')->where(array('infotype' => 'Hotels', 'token' => $this->token))->find();
                if ($pro) {
                   return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Hotels&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                } else {
                    return array(array(array('酒店', '酒店在线预订', rtrim($this->siteUrl, '/') . 'tpl/static/images/homelogo.png', $this->siteUrl . '/index.php?g=Wap&m=Hotels&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                }

                break;

            case '团购':

               $pro = M('reply_info')->where(array('infotype' => 'Groupon', 'token' => $this->token))->find();
                $url = $this->siteUrl . '/index.php?g=Wap&m=Groupon&a=grouponIndex&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '';
                if ($pro['apiurl']) {
                    $url = str_replace('&amp;', '&', $pro['apiurl']);
                }
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $url)), 'news');
                break;

            case '全景':

                $pro = M('reply_info')->where(array('infotype' => 'panorama', 'token' => $this->token))->find();
                if ($pro) {
                    return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Panorama&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                } else {
                    return array(array(array('360°全景看车看房', '通过该功能可以实现3D全景看车看房', rtrim($this->siteUrl, '/') . '/tpl/User/default/common/images/panorama/360view.jpg', $this->siteUrl . '/index.php?g=Wap&m=Panorama&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                }

                break;

            case 'wzz':
                $knwx = M('wzzreplay')->where(array('open' => '1', 'token' => $this->token))->find();
                if ($knwx == false) {
                    return array('目前微杂志模块关闭了', 'text');
                }

                $kndata['token'] = $this->token;

                $kndata['wecha_id'] = $this->data['FromUserName'];

                $kndata['knwxopen'] = 1;

                $kndata['time'] = time();

                $kndata['style'] = 1;

                $kndata['title'] = '我的微杂志';

                $res = M('wzzmy')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'knwxopen' => 1))->find();

                if ($res == false) {

                    $kndata['catgroy'] = time();

                    $re = M('wzzmy')->add($kndata);

                    if ($re == false) {

                        return array('无法进微杂志制作模式', 'text');

                    }

                }

                if ($knwx) {

                    S('wzz_' . $this->token . '_' . $this->data['FromUserName'], NULL);

                    return array('您已经进入了微杂志制作模式,回复图片即可', 'text');

                }

                break;

            case '交友':

			 $fconfig = M('Forum_config')->where(array('token' => $this->token))->find();
                return array(array(array($fconfig['forumname'], str_replace('&nbsp;', '', strip_tags(htmlspecialchars_decode($fconfig['intro']))), $fconfig['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Wechat_group&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');

            case '论坛':

                $fconfig = M('Forum_config')->where(array('token' => $this->token))->find();
                return array(array(array($fconfig['forumname'], str_replace('&nbsp;', '', strip_tags(htmlspecialchars_decode($fconfig['intro']))), $fconfig['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Forum&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                break;

            case '微商圈':
                $thisItem = M('Market')->where(array('token' => $this->token))->find();
                return array(array(array($thisItem['title'], $thisItem['address'], $thisItem['logo_pic'], $this->siteUrl . U('Wap/Market/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'])))), 'news');
                break;
                break;

            case '客服接口':
                $Estate = M('Estate')->where(array('token' => $this->token))->find();
                return array(array(array($Estate['title'], str_replace('&nbsp;', '', strip_tags(htmlspecialchars_decode($Estate['estate_desc']))), $Estate['cover'], $this->siteUrl . '/index.php?g=Wap&m=Test&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $Estate['id'] . '')), 'news');

            case '微房产':
                $Estate = M('Estate')->where(array('token' => $this->token))->find();
                return array(array(array($Estate['title'], str_replace('&nbsp;', '', strip_tags(htmlspecialchars_decode($Estate['estate_desc']))), $Estate['cover'], $this->siteUrl . '/index.php?g=Wap&m=Estate&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $Estate['id'] . '')), 'news');
                break;
            case '吃粽子':
                $pro = M('czzreply_info')->where(array('token' => $this->token))->find();
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Czz&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                break;
            case '2048加强版':
               $pro = M('gametreply_info')->where(array('token' => $this->token))->find();
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Gamet&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                break;
            case 'fly2048':
               $pro = M('gamettreply_info')->where(array('token' => $this->token))->find();
                return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Gamett&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                break;

        }

        $check = $this->user('diynum', $key);
        if ($check['diynum'] != 1) {
            return array(C('connectout'), 'text');
        }
        $like['keyword'] = $key;
        $like['precisions'] = 1;
        $like['token'] = $this->token;
        $data = M('keyword')->where($like)->order('id desc')->find();

        if (!$data) {
            $like['keyword'] = array('like', '%' . $key . '%');
            $like['precisions'] = 0;
            $data = M('keyword')->where($like)->order('id desc')->find();
        }

        if ($data != false) {
            $this->behaviordata($data['module'], $data['pid']);
            $replyClassName = $data['module'] . 'Reply';
            if (class_exists($replyClassName)) {
                $replyClass = new $replyClassName($this->token, $this->data['FromUserName'], $data, $this->siteUrl);
                return $replyClass->index();
            } else {
                switch ($data['module']) {

                    case 'Wifi':
                        $this->requestdata('other');
                        $pro = M('Wifi')->where(array('id' => $data['pid']))->find();
                        $pro['intro'] = str_replace('&nbsp;', '', $pro['intro']);
                        return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['intro'])), $pro['picurl'], $pro['url'])), 'news');
                        break;

                    case 'Scenes':
                        $this->requestdata('other');
                        $scene = M('Scene')->where(array('id' => $data['pid'], 'token' => $this->token))->find();
                        return array(array(array($scene['title'], $this->handleIntro($scene['info']), $scene['picurl'], C('site_url') . '/index.php?g=Wap&m=Scenes&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Hforward':

                        $this->requestdata('other');
                        $Hforward = M('Hforward')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hforward['title'], $this->handleIntro($Hforward['jianjie']), $Hforward['picurl'], C('site_url') . '/index.php?g=Wap&m=Hforward&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Hcar':
                        $this->requestdata('other');
                        $Hcarreplay = M('Hcarreplay')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hcarreplay['title'], $this->handleIntro($Hcarreplay['jianjie']), $Hcarreplay['pic'], C('site_url') . '/index.php?g=Wap&m=Hcar&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Wk':
                        $this->requestdata('other');
                        $Wkreplay = M('Wkreplay')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Wkreplay['title'], $this->handleIntro($Wkreplay['jianjie']), $Wkreplay['pic'], C('site_url') . '/index.php?g=Wap&m=Wk&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Kawahk':
                        $this->requestdata('other');
                        $Hcarreplay = M('Kawahkreplay')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hcarreplay['title'], $this->handleIntro($Hcarreplay['jianjie']), $Hcarreplay['pic'], C('site_url') . '/index.php?g=Wap&m=Kawahk&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Xinniannq':
                        $this->requestdata('other');
                        $Hcarreplay = M('Xinniannqreplay')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hcarreplay['title'], $this->handleIntro($Hcarreplay['jianjie']), $Hcarreplay['pic'], C('site_url') . '/index.php?g=Wap&m=Xinniannq&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Musiccar':
                        $this->requestdata('other');
                        $Musiccar = M('Musiccar')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Musiccar['title'], $this->handleIntro($Musiccar['jianjie']), $Musiccar['pic'], C('site_url') . '/index.php?g=Wap&m=Musiccar&a=index&tx=1&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Img':

                        $this->requestdata('imgnum');
                        $img_db = M($data['module']);
                        $back = $img_db->field('id,text,pic,url,title')->limit(9)->order('usort desc')->where($like)->select();
                        if ($back == false) {
                            return array('‘' . $data['keyword'] . '’无此图文信息或图片,请提醒商家，重新设定关键词', 'text');
                        }
                        $idsWhere = 'id in (';
                        $comma = '';
                        foreach ($back as $keya => $infot) {
                            $idsWhere .= $comma . $infot['id'];
                            $comma = ',';
                            if ($infot['url'] != false) {
                                //处理外链
                                if (!(strpos($infot['url'], 'http') === FALSE)) {
                                    $url = $this->getFuncLink(html_entity_decode($infot['url']));
                                } else {
                                    //内部模块的外链
                                    $url = $this->getFuncLink($infot['url']);
                                }
                            } else {
                                $url = rtrim($this->siteUrl, '/') . U('Wap/Index/content', array('token' => $this->token, 'id' => $infot['id'], 'wecha_id' => $this->data['FromUserName']));
                            }
                            $return[] = array($infot['title'], $this->handleIntro($infot['text']), $infot['pic'], $url);
                        }

                        $idsWhere .= ')';
                        if ($back) {
                            $img_db->where($idsWhere)->setInc('click');
                        }
                        return array($return, 'news');
                        break;

                    case 'Host':
                        $this->requestdata('other');
                        $host = M('Host')->where(array('id' => $data['pid']))->find();
                        return array(array(array($host['name'], $host['info'], $host['ppicurl'], $this->siteUrl . '/index.php?g=Wap&m=Host&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Reservation':

                        $this->requestdata('other');
                        $rt = M('Reservation')->where(array('id' => $data['pid']))->find();
                        if (!strpos($rt['picurl'], 'ttp:')) {
                            $rt['picurl'] = $this->siteUrl . $rt['picurl'];
                        }
                        return array(array(array($rt['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($rt['info']))), $rt['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Reservation&a=index&rid=' . $data['pid'] . '&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                        break;

                    case 'Text':

                        $this->requestdata('textnum');
                        $info = M($data['module'])->order('id desc')->find($data['pid']);
                        return array(htmlspecialchars_decode(str_replace('{wechat_id}', $this->data['FromUserName'], $info['text'])), 'text');
                        break;

                    case 'Hvote':
                        $this->requestdata('other');
                        $Hvote = M('Hvote')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hvote['title'], $this->handleIntro($Hvote['jianjie']), $Hvote['picurl'], C('site_url') . '/index.php?g=Wap&m=Hvote&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Hvotes':
                        $this->requestdata('other');
                        $Hvotes = M('Hvotes')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Hvotes['title'], $this->handleIntro(), $Hvotes['picurl'], C('site_url') . '/index.php?g=Wap&m=Hvotes&a=toupiao&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Product':
                        $this->requestdata('other');
                        $infos = M('Product')->limit(9)->order('id desc')->where($like)->select();
                        if ($infos) {
                            $return = array();
                            foreach ($infos as $info) {
                                if (!$info['groupon']) {
                                    $url = $this->siteUrl . '/index.php?g=Wap&m=Store&a=product&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $info['id'];
                                } else {
                                    $url = $this->siteUrl . '/index.php?g=Wap&m=Groupon&a=product&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $info['id'];
                                }
                                $return[] = array($info['name'], $this->handleIntro(strip_tags(htmlspecialchars_decode($info['intro']))), $info['logourl'], $url);
                            }
                        }
                        return array($return, 'news');
                        break;

                    case 'Kefu':

                        $this->requestdata('other');
                        $kefu = M('Kefu')->where(array('token' => $data['token']))->find();
                        return array(array(array($kefu['title'], $kefu['text'], $kefu['picurl'], strip_tags(htmlspecialchars_decode($kefu['info2'])))), 'news');
                        break;

                    case 'Selfform':

                        $this->requestdata('other');
                        $pro = M('Selfform')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['name'], strip_tags(htmlspecialchars_decode($pro['intro'])), $pro['logourl'], $this->siteUrl . '/index.php?g=Wap&m=Selfform&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Jingcai':
                        $this->requestdata('other');
                        $pro = M('JingcaiSet')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['sttxt'])), $pro['cover'], $this->siteUrl . '/index.php?g=Wap&m=Jingcai&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '')), 'news');
                        break;

                    case 'eqx':

                        $this->requestdata('other');
                        $pro = M('eqx_info')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['info'])), $pro['picurl'], $pro['url'])), 'news');
                        break;

                    case 'zhaopianwall':

                        $thisItem = M('pic_wall')->where(array('token' => $this->token, 'status' => 1))->order('id desc')->find();
                        if (!$thisItem) {
                            return array('图片上墙失败！还未开启照片墙功能。', 'text');
                       }

                        return array(array(array($thisItem['title'], $this->handleIntro($thisItem['info']), $thisItem['starpicurl'], C('site_url') . '/index.php?g=Wap&m=Zhaopianwall&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com')), 'news');
                        break;

                    case 'Weizhuli':
                        $this->requestdata('other');
                        $pro = M('Weizhuli')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['title'], $this->handleIntro($pro['nr']), $pro['picurl'], C('site_url') . U('Wap/Weizhuli/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'ids' => $data['pid'])))), 'news');
                        break;

                    case 'Helping':
                        $this->requestdata('other');
                        $pro = M('Helping')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['title'], $this->handleIntro($pro['intro']), $pro['reply_pic'], C('site_url') . U('Wap/Helping/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $data['pid'])))), 'news');
                        break;

                    case 'Popularity':

                        $this->requestdata('other');
                        $pro = M('Popularity')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['title'], $this->handleIntro($pro['intro']), $pro['pic'], C('site_url') . U('Wap/Popularity/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $data['pid'])))), 'news');

                        break;

                    //----------------------有奖拉票开始-------------------------------------//

                    case 'Lapiao':

                        $this->requestdata('other');
                        $pro = M('Lapiao')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['name'], strip_tags(htmlspecialchars_decode($pro['Lapiao'])), $pro['pic'], $this->siteUrl . '/index.php?g=Wap&m=Lapiao&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');

                        break;

                    //----------------------有奖拉票结束-------------------------------------//

                    case 'Lapiao':

                        $this->requestdata('other');
                        $thisItem = M('Lapiao')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['name'], $this->handleIntro($thisItem['ms']), $thisItem['pic'], C('site_url') . U('Wap/Lapiao/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $data['pid'])))), 'news');
                        break;

                    case 'Custom':
                        $this->requestdata('other');
                        $pro = M('Custom_set')->where(array('set_id' => $data['pid']))->find();
                        return array(array(array($pro['title'], strip_tags(htmlspecialchars_decode($pro['intro'])), $pro['top_pic'], $this->siteUrl . '/index.php?g=Wap&m=Custom&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');

                        break;

                    case 'Panorama':
                        $this->requestdata('other');
                        $pro = M('Panorama')->where(array('id' => $data['pid']))->find();
                        return array(array(array($pro['name'], strip_tags(htmlspecialchars_decode($pro['intro'])), $pro['frontpic'], $this->siteUrl . '/index.php?g=Wap&m=Panorama&a=item&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Wqlvote':
                        $this->requestdata('other');
                        $Vote = M('Wqlvote')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Vote['title'], '', $Vote['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Wqlvote&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Wedding':

                        $this->requestdata('other');

                        $wedding = M('Wedding')->where(array('id' => $data['pid']))->find();

                        return array(array(array($wedding['title'], strip_tags(htmlspecialchars_decode($wedding['word'])), $wedding['coverurl'], $this->siteUrl . '/index.php?g=Wap&m=Wedding&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . ''), array('查看我的祝福', strip_tags(htmlspecialchars_decode($wedding['word'])), $wedding['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Wedding&a=check&type=1&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . ''), array('查看我的来宾', strip_tags(htmlspecialchars_decode($wedding['word'])), $wedding['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Wedding&a=check&type=2&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');

                        break;

                    case 'Vote':
                        $this->requestdata('other');
                        $Vote = M('Vote')->where(array('id' => $data['pid']))->order('id DESC')->find();
                        return array(array(array($Vote['title'], '', $Vote['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Vote&a=haibao&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Greeting_card':
                        $this->requestdata('other');
                        $Vote = M('Greeting_card')->where(array('id' => $data['pid']))->order('id DESC')->find();
                       return array(array(array($Vote['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($Vote['info']))), $Vote['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Greeting_card&a=index&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Estate':
                        $this->requestdata('other');
                        $Estate = M('Estate')->where(array('id' => $data['pid']))->find();
                        return array(array(array($Estate['title'], $Estate['estate_desc'], $Estate['cover'], $this->siteUrl . '/index.php?g=Wap&m=Estate&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . ''), array('楼盘介绍', $Estate['estate_desc'], $Estate['house_banner'], $this->siteUrl . '/index.php?g=Wap&m=Estate&a=index&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $data['pid'] . ''), array('专家点评', $Estate['estate_desc'], $Estate['cover'], $this->siteUrl . '/index.php?g=Wap&m=Estate&a=impress&&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $data['pid'] . ''), array('楼盘3D全景', $Estate['estate_desc'], $Estate['banner'], $this->siteUrl . '/index.php?g=Wap&m=Panorama&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $data['pid'] . ''), array('楼盘动态', $Estate['estate_desc'], $Estate['house_banner'], $this->siteUrl . '/index.php?g=Wap&m=Index&a=lists&classid=' . $Estate['classify_id'] . '&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Invites':
                        $this->requestdata('other');
                        $info = M('Invites')->where(array('id' => $data['pid']))->find();
                        if ($info == false) {
                            return array('商家未做邀请回复配置，请稍后再试', 'text');
                        }
                        return array(array(array($info['title'], $this->handleIntro($info['brief']), $info['picurl'], C('site_url') . U('Wap/Invites/index', array('token' => $this->token, 'id' => $info['id'])))), 'news');
                        break;

                    case 'Vcard':

                        $this->requestdata('other');
                        $vcard = M('vcard_list')->where(array('token' => $this->token, 'name' => $key))->find();
                        if ($vcard) {
                            return array(array(array($vcard['name'], $vcard['work'], $vcard['image'], $this->siteUrl . U('Wap/Vcard/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $vcard['id'])))), 'news');
                        }

                        break;

                    case 'Paper':
                        $this->requestdata('other');
                        $Paper = M('Paper')->where(array('id' => $data['pid']))->find();
                        return array(array(array($Paper['title'], strip_tags(htmlspecialchars_decode($Paper['title'])), $Paper['pic'], $this->siteUrl . '/index.php?g=Wap&m=Paper&a=item&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Jiejing':

                        $this->requestdata('other');
                        $Jiejing = M('Jiejing')->where(array('token' => $data['token']))->find();
                        $url = 'http://apis.map.qq.com/uri/v1/streetview?pano=' . $Jiejing['pano'] . '&heading=30&pitch=10';
                        return array(array(array($Jiejing['title'], $Jiejing['text'], C('site_url') . $Jiejing['picurl'], $url)), 'news');
                        break;

                    case 'RippleOS_url':
                        $this->requestdata('textnum');
                        $node = D('Rippleos_node')->where(array('id' => $data['pid']))->find();
                        $ret_json = $this->rippleos_auth_url($node['node']);
                        if (is_array($node) && $ret_json['status'] === 0) {
                            $ret = '<a href="' . $ret_json['auth_url'] . '">' . $node['text'] . '</a>';
                        } else {
                            $ret = $this->rptk_err_msg[abs($ret_json['status'])];
                        }
                        return array(htmlspecialchars_decode($ret), 'text');
                        break;

                    case 'RippleOS_code':
                        $this->requestdata('textnum');
                        $node = D('Rippleos_node')->where(array('id' => $data['pid']))->find();
                        $ret_json = $this->rippleos_auth_token($node['node']);
                        if (is_array($node) && $ret_json['status'] === 0) {
                            $ret = '上网验证码:' . $ret_json['auth_token'] . '(验证码有效期为10分钟)';
                        } else {
                            $ret = $this->rptk_err_msg[abs($ret_json['status'])];
                        }
                        return array(htmlspecialchars_decode($ret), 'text');
                        break;

                    case 'Lottery':
                        $this->requestdata('other');
                        $info = M('Lottery')->find($data['pid']);
                        if ($info == false || $info['status'] == 3) {
                            return array('活动可能已经结束或者被删除了', 'text');
                        }
                        switch ($info['type']) {
                            case 1:
                                $model = 'Lottery';
                                break;
                            case 2:
                                $model = 'Guajiang';
                                break;
                            case 3:
                                $model = 'Coupon';
                                break;
                            case 4:
                                $model = 'LuckyFruit';
                                break;
                            case 5:
                                $model = 'GoldenEgg';
                                break;
                            case 7:
                                $model = 'AppleGame';
                                break;
                            case 8:
                                $model = 'Lovers';
                                break;
                            case 9:
                                $model = 'Autumn';
                                break;
                        }

                        $id = $info['id'];
                        $type = $info['type'];

                        if ($info['status'] == 1) {
                            $picurl = $info['starpicurl'];
                            $title = $info['title'];
                            $id = $info['id'];
                            $info = $info['info'];

                        } else {
                            $picurl = $info['endpicurl'];
                            $title = $info['endtite'];
                            $info = $info['endinfo'];
                        }

                        $url = $this->siteUrl . U('Wap/' . $model . '/index', array('token' => $this->token, 'type' => $type, 'wecha_id' => $this->data['FromUserName'], 'id' => $id, 'type' => $type));
                        return array(array(array($title, $info, $picurl, $url)), 'news');
                    case 'Carowner':
                        $this->requestdata('other');
                        $thisItem = M('Carowner')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($thisItem['info']))), $thisItem['head_url'], $this->siteUrl . '/index.php?g=Wap&m=Car&a=owner&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&id=' . $data['pid'] . '')), 'news');
                        break;

                    case 'Carowner':
                        $this->requestdata('other');
                        $thisItem = M('Carowner')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($thisItem['info']))), $thisItem['head_url'], $this->siteUrl . '/index.php?g=Wap&m=Car&a=owner&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                        break;

                    case 'Carset':
                        $this->requestdata('other');
                        $thisItem = M('Carset')->where(array('id' => $data['pid']))->find();
                        $news = array();
                        array_push($news, array($thisItem['title'], '', $thisItem['head_url'], $thisItem['url'] ? $thisItem['url'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title1'], '', $thisItem['head_url_1'], $thisItem['url1'] ? $thisItem['url1'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=brands&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title2'], '', $thisItem['head_url_2'], $thisItem['url2'] ? $thisItem['url2'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=salers&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title3'], '', $thisItem['head_url_3'], $thisItem['url3'] ? $thisItem['url3'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=CarReserveBook&addtype=drive&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title4'], '', $thisItem['head_url_4'], $thisItem['url4'] ? $thisItem['url4'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=owner&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title5'], '', $thisItem['head_url_5'], $thisItem['url5'] ? $thisItem['url5'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=tool&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        array_push($news, array($thisItem['title6'], '', $thisItem['head_url_6'], $thisItem['url6'] ? $thisItem['url6'] : $this->siteUrl . '/index.php?g=Wap&m=Car&a=showcar&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName']));
                        return array($news, 'news');
                        break;

                    case 'medicalSet':
                        $thisItem = M('Medical_set')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($thisItem['info']))), $thisItem['head_url'], $this->siteUrl . '/index.php?g=Wap&m=Medical&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'])), 'news');
                        break;

                    case 'Wall':
                    case 'Scene':
                        if ($data['module'] == 'Wall') {
                            $act_model = M('Wall');
                        } else {
                            $act_model = M('Wechat_scene');
                        }
                        $thisItem = $act_model->where(array('id' => $data['pid']))->find();
                        if ($data['module'] == 'Wall') {
                            $acttype = 1;
                            $isopen = $thisItem['isopen'];
                            $picLogo = $thisItem['startbackground'];
                        } else {
                            $acttype = 3;
                            $isopen = $thisItem['is_open'];
                            $picLogo = $thisItem['pic'];
                        }
                        $str = $this->wallStr($acttype, $thisItem);
                        if (!$isopen) {
                            return array($thisItem['title'] . '活动已关闭', 'text');
                        } else {
                            $actid = $data['pid'];
                            $memberRecord = M('Wall_member')->where(array('act_id' => $actid, 'act_type' => $acttype, 'wecha_id' => $this->data['FromUserName']))->find();
                            if (!$memberRecord) {
                                    return array(array(array($thisItem['title'], '请点击这里完善信息后再参加此活动', $picLogo, $this->siteUrl . U('Wap/Scene_member/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'act_type' => $acttype, 'id' => $actid, 'name' => 'wall')))), 'news');
                            } else {
                                M('Userinfo')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->save(array('wallopen' => 1));
                                S('fans_' . $this->token . '_' . $this->data['FromUserName'], NULL);
                                return array($str, 'text');
                            }
                        }
                        break;

                    case 'Recipe':
                        $this->requestdata('other');
                        $thisItem = M('Recipe')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($thisItem['infos']))), $thisItem['headpic'], $this->siteUrl . '/index.php?g=Wap&m=Recipe&a=index&token=' . $this->token . '&type=' . $thisItem['type'] . '&id=' . $thisItem['id'] . 'wecha_id=' . $this->data['FromUserName'])), 'news');
                        break;

                    case 'Router_config':

                        $routerUrl = Router::login($this->token, $this->data['FromUserName']);
                        $thisItem = M('Router_config')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['info'], $thisItem['picurl'], $routerUrl)), 'news');

                        break;

                    case 'Schoolset':

                        $thisItem = M('School_set_index')->where(array('setid' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['info'], $thisItem['head_url'], $this->siteUrl . U('Wap/School/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'])))), 'news');
                        break;

                    case 'Research':

                        $thisItem = M('Research')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['description'], $thisItem['logourl'], $this->siteUrl . U('Wap/Research/index', array('reid' => $data['pid'], 'token' => $this->token, 'wecha_id' => $this->data['FromUserName'])))), 'news');
                        break;

                    case 'Business':

                        $this->requestdata('other');
                        $thisItem = M('Busines')->where(array('bid' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], str_replace(array('&nbsp;', 'br /', '&amp;', 'gt;', 'lt;'), '', strip_tags(htmlspecialchars_decode($thisItem['business_desc']))), $thisItem['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Business&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&bid=' . $thisItem['bid'] . '&type=' . $thisItem['type'])), 'news');
                        break;

                    case 'Sign':
                        $thisItem = M('Sign_set')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['content'], $thisItem['reply_img'], $this->siteUrl . U('Wap/Fanssign/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $data['pid'])))), 'news');
                        break;

                    case 'Punish':

                        $thisItem = M('Punish')->where(array('id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['info'], $thisItem['pic'], $this->siteUrl . U('Wap/Punish/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $data['pid'])))), 'news');
                        break;

                    case 'Multi':
                        $multiImgClass = new multiImgNews($this->token, $this->data['FromUserName'], $this->siteUrl);
                        return $multiImgClass->news($data['pid']);
                        break;

                    case 'Market':
                        $thisItem = M('Market')->where(array('market_id' => $data['pid']))->find();
                        return array(array(array($thisItem['title'], $thisItem['address'], $thisItem['logo_pic'], $this->siteUrl . U('Wap/Market/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'])))), 'news');

                    default:

                        $replyClassName = $data['module'] . 'Reply';
                        if (class_exists($replyClassName)) {
                            $replyClass = new $replyClassName($this->token, $this->data['FromUserName'], $data, $this->siteUrl);
                            return $replyClass->index();
                        } else {
                            $this->requestdata('videonum');
                            $info = M($data['module'])->order('id desc')->find($data['pid']);
                            return array(array($info['title'], $info['keyword'], $info['musicurl'], $info['hqmusicurl']), 'music');

                        }
                }
            }
        } else {
            $nokeywordReply = $this->nokeywordApi();
            if ($nokeywordReply) {
                return $nokeywordReply;
            }
            if ($this->wxuser['transfer_customer_service']) {
               return array('turn on transfer_customer_service', 'transfer_customer_service');//转移到多客服处理
            }
            S('service_'.$this->data['FromUserName'],NULL);

//            if(S('service_'.$this->data['FromUserName'])){
//                return array(S('service_'.$this->data['FromUserName']), 'transfer_customer_service');
//            }

            //----------------------有奖拉票开始-------------------------------------//

            // 拉票投票处理
            $lprd = M('lapiao_record')->where(array('token' => $this->token, 'sn' => $key))->find();
            if ($lprd) {
                $lp = M('lapiao')->where(array('id' => $lprd['tid']))->find();
                $jssj = strtotime($lp['jssj']);
                $nowtime = time();
                $wql = $nowtime - $jssj;
                if ($wql > 0) {
                    $str1 = '对不起，投票已经结束了，不允许投票了哦！';
                    return array($str1, 'text');
                }

                if (strtotime($lp['kssj']) > time()) {
                    return array('拉票活动还未开始哦！请不要着急！', 'text');
                }

                $lptp = M('lapiao_toupiao')->where(array('wxid' => $this->data['FromUserName'], 'tid' => $lprd['tid'], 'sn' => $key))->find();
                if ($lptp) {
                    return array('你已经给Ta投过票了，快去拉小伙伴来投吧', 'text');
                }
                $lptps = M('lapiao_toupiao')->where(array('wxid' => $this->data['FromUserName'], 'tid' => $lprd['tid']))->count();
                if ($lptps > 3) {
                    return array('每人最多只允许给三个人投票哦', 'text');
                }

                //return array($str2, 'text');

                // 插入投票数据

                $lptp['wxid'] = $this->data['FromUserName'];
                $lptp['sn'] = $key;
                $lptp['tid'] = $lprd['tid'];
                M('lapiao_toupiao')->add($lptp);
                $lprd['sl'] = $lprd['sl'] + 1;
                M('lapiao_record')->save($lprd);
                return array('给【' . $lprd['un'] . '】投票成功', 'text');

            }

            //----------------------有奖拉票结束-------------------------------------//

            $chaFfunction = M('Function')->where(array('funname' => 'liaotian'))->find();
            if (!strpos($this->fun, 'liaotian') || !$chaFfunction['status']) {
                $other = M('Other')->where(array('token' => $this->token))->find();
                if ($other == false) {
                    return array('请在平台里设置回答不上来的回复', 'text');
                } else {
                    if (empty($other['keyword'])) {
                        return array($other['info'], 'text');
                    } else {
                        $img = M('Img')->field('id,text,pic,url,title')->limit(10)->order('usort desc')->where(array('token' => $this->token, 'keyword' => array('like', '%' . $other['keyword'] . '%')))->select();
                        if ($img == false) {
                            $multiImgs = M('Img_multi')->where(array('token' => $this->token, 'keywords' => array('like', '%' . $other['keyword'] . '%')))->find();
                            if (!$multiImgs) {
                                return array('无此图文信息,请提醒商家，重新设定关键词', 'text');
                            } else {
                                $multiImgClass = new multiImgNews($this->token, $this->data['FromUserName'], $this->siteUrl);
                                return $multiImgClass->news($multiImgs['id']);
                            }
                        }
                       foreach ($img as $keya => $infot) {
                           if ($infot['url'] != false) {
                                //处理外链
                                if (!(strpos($infot['url'], 'http') === FALSE)) {
                                    $url = $this->getFuncLink(html_entity_decode($infot['url']));
                                } else {
                                    //内部模块的外链
                                    $url = $this->getFuncLink($infot['url']);
                                }
                            } else {
                                $url = rtrim($this->siteUrl, '/') . U('Wap/Index/content', array('token' => $this->token, 'id' => $infot['id'], 'wecha_id' => $this->data['FromUserName']));
                            }
                           $return[] = array($infot['title'], $infot['text'], $infot['pic'], $url);
                        }
                        return array($return, 'news');
                    }
               }
            }
            if (!C('not_support_chat')) {
                $this->selectService();
            }
            return array($this->chat($key), 'text');
        }
    }

    //微信墙开启模式下的回复
    private function wallStr($acttype, $thisItem)
    {
        $str = '处理成功，您下面发送的所有文字和图片都将会显示在“' . $thisItem['title'] . '”大屏幕上，如需退出微信墙模式，请输入“quit”';
        if ($acttype == 3) {
            if ($thisItem['shake_id']) {
                $str .= '
<a href="' . $this->siteUrl . '/index.php?g=Wap&m=Shake&a=index&id=' . $thisItem['id'] . '&token=' . $this->token . '&act_type=' . $acttype . '&wecha_id=' . $this->data['FromUserName'] . '">点击这里参与摇一摇活动</a>';
            }
            if ($thisItem['vote_id']) {
                $str .= '
<a href="' . $this->siteUrl . '/index.php?g=Wap&m=Scene_vote&a=index&id=' . $thisItem['id'] . '&token=' . $this->token . '&act_type=' . $acttype . '&wecha_id=' . $this->data['FromUserName'] . '">点击这里参与投票</a>';
            }
        }
        return $str;
    }

    //无关键词触发的第三方接口回答
    private function nokeywordApi()    {
        if (!(strpos($this->fun, 'api') === FALSE)) {
            $apiwhere = array('token' => $this->token, 'status' => 1, 'noanwser' => 1);
            $apiwhere['noanswer'] = array('gt', 0);
            $api = M('Api')->where($apiwhere)->find();
            if ($api != false) {
                $vo['fromUsername'] = $this->data['FromUserName'];
                $vo['Content'] = $this->data['Content'];
                if (intval($api['is_colation'])) {
                    $vo['Content'] = trim(str_replace($api['keyword'], '', $this->data['Content']));
                }
                $vo['toUsername'] = $this->token;
                $api['url'] = $this->getApiUrl($api['url'], $api['apitoken']);
                if ($api['type'] == 2) {
                    $apidata = $this->api_notice_increment($api['url'], $vo, 0, 0);
                    return array($apidata, 'text');
                } else {
                    $xml = file_get_contents('php://input');
                    if (intval($api['is_colation'])) {
                        $xml = str_replace(array($api['keyword'], $api['keyword'] . ' '), '', $xml);
                    }
                    $apidata = $this->api_notice_increment($api['url'], $xml, 0);
                    if ($apidata != 'false') {
                        header('Content-type: text/xml');
                        die($apidata);
                        return false;
                    }
                }
            }
        }
    }

    //给第三方接口加上参数
    private function getApiUrl($url, $token)
    {
        $timestamp = time();
        $nonce = $_GET['nonce'];
        $tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode($tmpArr);
        $signature = sha1($tmpStr);
        if (strpos($url, '?')) { $url = $url . '&fromthirdapi=1&signature=' . $signature . '&timestamp=' . $timestamp . '&nonce=' . $nonce . '&apitoken=' . $this->token;
        } else {
            $url = $url . '?fromthirdapi=1&signature=' . $signature . '&timestamp=' . $timestamp . '&nonce=' . $nonce . '&apitoken=' . $this->token;
        }
        return $url;
    }

    //获得各个功能的链接
    private function getFuncLink($u)
    {
        $urlInfos = explode(' ', $u);
        switch ($urlInfos[0]) {
            default:
                $url = str_replace(array('{wechat_id}', '{siteUrl}', '&amp;'), array($this->data['FromUserName'], $this->siteUrl, '&'), $urlInfos[0]);
                break;
            case '刮刮卡':
                $Lottery = M('Lottery')->where(array('token' => $this->token, 'type' => 2, 'status' => 1))->order('id DESC')->find();
                $url = $this->siteUrl . U('Wap/Guajiang/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $Lottery['id']));
                break;
            case '大转盘':
                $Lottery = M('Lottery')->where(array('token' => $this->token, 'type' => 1, 'status' => 1))->order('id DESC')->find();
                $url = $this->siteUrl . U('Wap/Lottery/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $Lottery['id']));
                break;
            case '商家订单':
                $url = $this->siteUrl . '/index.php?g=Wap&m=Host&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&hid=' . $urlInfos[1] . '';
                break;
            case '优惠券':
                $Lottery = M('Lottery')->where(array('token' => $this->token, 'type' => 3, 'status' => 1))->order('id DESC')->find();
                $url = $this->siteUrl . U('Wap/Coupon/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $Lottery['id']));
                break;
            case '万能表单':
                $url = $this->siteUrl . U('Wap/Selfform/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $urlInfos[1]));
                break;
            case '会员卡':
                $url = $this->siteUrl . U('Wap/Card/vip', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));
                break;
            case '首页':
                $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Index&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'];
                break;
            case '团购':
                $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Groupon&a=grouponIndex&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'];
                break;
            case '商城':
                $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Store&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'];
                break;
            case '订餐':
                $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Repast&a=index&dining=1&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'];
                break;
            case '相册':
                $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Photo&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'];
                break;
            case '网站分类':
                $url = $this->siteUrl . U('Wap/Index/lists', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'classid' => $urlInfos[1]));
                break;
            case 'LBS信息':
                if ($urlInfos[1]) {
                    $url = $this->siteUrl . U('Wap/Company/map', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'companyid' => $urlInfos[1]));
                } else {
                    $url = $this->siteUrl . U('Wap/Company/map', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']));
                }
                break;
            case 'DIY宣传页':
                $url = $this->siteUrl . '/index.php/show/' . $this->token;
                break;
            case '婚庆喜帖':
                $url = $this->siteUrl . U('Wap/Wedding/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $urlInfos[1]));
               break;
            case '投票':
                $url = $this->siteUrl . U('Wap/Vote/index', array('token' => $this->token, 'wecha_id' => $this->data['FromUserName'], 'id' => $urlInfos[1]));
                break;
        }
        return $url;
    }

	public static function https_post($url, $data){
		$ch = curl_init();
		$header = "Accept-Charset: utf-8";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$tmpInfo = curl_exec($ch);
		$errorno=curl_errno($ch);
		if ($errorno) {
			return array('rt'=>false,'errorno'=>$errorno);
		}else{
			$js=json_decode($tmpInfo,1);
			if ($js['errcode']=='0'){
				return array('rt'=>true,'errorno'=>0);
			}else {
				$errmsg=GetErrorMsg::wx_error_msg($js['errcode']);
			}
		}
	}   

    //首页
    private function home()
    {
        return $this->shouye();
    }

    private function shouye()
    {
        $home = M('Home')->where(array('token' => $this->token))->find();
        $this->behaviordata('home', '', '1');
        if ($home == false) {
            return array('首页正在制作中，请稍后再试', 'text');
        } else {
            $imgurl = $home['picurl'];
            if ($home['apiurl'] == false) {
                if (!$home['advancetpl']) {
                   $url = rtrim($this->siteUrl, '/') . '/index.php?g=Wap&m=Index&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '';
                } else {
                    $url = rtrim($this->siteUrl, '/') . '/cms/index.php?token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '';
                }
            } else {
                $url = $home['apiurl'];
            }
        }
        return array(array(array($home['title'], $home['info'], $imgurl, $url)), 'news');
    }

    //快递

    private function kuaidi($data)
    {
        //TODO：这里申请快递100
    }

    //rippletek微路由

    public static function postJson($url, $jsonData)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function rippleos_auth_url($node)
    {
        $this->rptk_err_msg = array('数据库错误', '请求格式错误', '参数不完整', '参数类型错误', '服务器错误', '节点不存在', '认证API ID或KEY错误', '不存在对应的OPENID');
        $date = array('api_id' => C('rptk_wx_auth_api_id'), 'api_key' => C('rptk_wx_auth_api_key'), 'node' => intval($node), 'openid' => $this->data['FromUserName']);
        return json_decode($this->postJson('http://wx.rippletek.com/Portal/Wx/get_auth_url', json_encode($date)), true);
    }

    private function rippleos_auth_token($node)
    {
        $this->rptk_err_msg = array('数据库错误', '请求格式错误', '参数不完整', '参数类型错误', '服务器错误', '节点不存在', '认证API ID或KEY错误', '不存在对应的OPENID');
        $date = array('api_id' => C('rptk_wx_auth_api_id'), 'api_key' => C('rptk_wx_auth_api_key'), 'node' => intval($node), 'openid' => $this->data['FromUserName']);
        return json_decode($this->postJson('http://wx.rippletek.com/Portal/Wx/get_auth_token', json_encode($date)), true);
    }

    private function rippleos_unauth($node)
    {
        $date = array('api_id' => C('rptk_wx_auth_api_id'), 'api_key' => C('rptk_wx_auth_api_key'), 'node' => intval($node), 'openid' => $this->data['FromUserName']);
        $ret = json_decode($this->postJson('http://wx.rippletek.com/Portal/Wx/unauth_user', json_encode($date)), true);
        return;
    }

    //朗读
    private function langdu($data)
    {
        $data = implode('', $data);
        $mp3url = 'http://www.apiwx.com/aaa.php?w=' . urlencode($data);
        return array(array($data, '点听收听', $mp3url, $mp3url), 'music');
    }

    //健康

    private function jiankang($data)
    {
        if (empty($data)) {
            return '主人，' . $this->my . '提醒您正确的查询方式是:健康+身高,+体重 例如：健康170,65';
        }

        $height = $data[1] / 100;
        $weight = $data[2];
        $Broca = ($height * 100 - 80) * 0.7;
        $kaluli = 66 + 13.7 * $weight + 5 * $height * 100 - 6.8 * 25;
        $chao = $weight - $Broca;
        $zhibiao = $chao * 0.1;
        $res = round($weight / ($height * $height), 1);
        if ($res < 18.5) {
            $info = '您的体形属于骨感型，需要增加体重' . $chao . '公斤哦!';
            $pic = 1;
        } elseif ($res < 24) {
            $info = '您的体形属于圆滑型的身材，需要减少体重' . $chao . '公斤哦!';
        } elseif ($res > 24) {
            $info = '您的体形属于肥胖型，需要减少体重' . $chao . '公斤哦!';
        } elseif ($res > 28) {
            $info = '您的体形属于严重肥胖，请加强锻炼，或者使用我们推荐的减肥方案进行减肥';
        }
        return $info;
    }

    //附近

    private function fujin($keyword)
    {
        $keyword = implode('', $keyword);
        if ($keyword == false) {
            return $this->my . '很难过,无法识别主人的指令,正确使用方法是:输入【附近+关键词】当' . $this->my . '提醒您输入地理位置的时候就OK啦';
        }
        $data = array();
        $data['time'] = time();
        $data['token'] = $this->_get('token');
        $data['keyword'] = $keyword;
        $data['uid'] = $this->data['FromUserName'];
        $re = M('Nearby_user');
        $user = $re->where(array('token' => $this->_get('token'), 'uid' => $data['uid']))->find();
        if ($user == false) {
            $re->data($data)->add();
        } else {
            $id['id'] = $user['id'];
            $re->where($id)->save($data);
        }
        return '主人【' . $this->my . '】已经接收到你的指令
请发送您的地理位置(对话框右下角点击＋号，然后点击“位置”)给我哈';
    }

    //我要上网

    private function wysw()
    {
        $routerUrl = Router::login($this->token, $this->data['FromUserName']);
        $thisItem = M('Router_config')->where(array('token' => $this->token))->find();
        return array(array(array($thisItem['title'], $thisItem['info'], $thisItem['picurl'], $routerUrl)), 'news');
    }

    //记录最近一次的用户请求
    private function recordLastRequest($key, $msgtype = 'text')
    {
        $rdata = array();
        $rdata['time'] = time();
        $rdata['token'] = $this->_get('token');
        $rdata['keyword'] = $key;
        $rdata['msgtype'] = $msgtype;
        $rdata['uid'] = $this->data['FromUserName'];
        $user_request_model = M('User_request');
        $user_request_row = $user_request_model->where(array('token' => $this->_get('token'), 'msgtype' => $msgtype, 'uid' => $rdata['uid']))->find();
        if (!$user_request_row) {
            $user_request_model->add($rdata);
        } else {
            $rid['id'] = $user_request_row['id'];
            $user_request_model->where($rid)->save($rdata);
        }
    }

    //地图

    public function map($x, $y)
    {
        if (C('baidu_map')) {
            $transUrl = 'http://api.map.baidu.com/ag/coord/convert?from=2&to=4&x=' . $x . '&y=' . $y;
            $json = Http::fsockopenDownload($transUrl);
            if ($json == false) {
                $json = file_get_contents($transUrl);
            }
            $arr = json_decode($json, true);
            $x = base64_decode($arr['x']);
            $y = base64_decode($arr['y']);
        } else {
            $amap = new amap();
            $lact = $amap->coordinateConvert($y, $x, 'gps');
            $x = $lact['latitude'];
            $y = $lact['longitude'];
        }
        $user_request_model = M('User_request');
        $urWhere = array('token' => $this->_get('token'), 'msgtype' => 'text', 'uid' => $this->data['FromUserName']);
        $urWhere['time'] = array('gt', time() - 5 * 60);
        $user_request_row = $user_request_model->where($urWhere)->find();
        if (!(strpos($user_request_row['keyword'], '附近') === FALSE)) {
            $user = M('Nearby_user')->where(array('token' => $this->_get('token'), 'uid' => $this->data['FromUserName']))->find();
            $keyword = $user['keyword'];
            $radius = 2000;
            if (C('baidu_map')) {
                $map = new baiduMap($keyword, $x, $y);
                $str = $map->echoJson();
                $array = json_decode($str);
                $map = array();
                foreach ($array as $key => $vo) {
                    $map[] = array($vo->title, $key, rtrim($this->siteUrl, '/') . '/tpl/static/images/home.jpg', $vo->url);
                }
                if ($map) {
                    return array($map, 'news');
                } else {
                    $str = file_get_contents($this->siteUrl . '/map.php?keyword=' . urlencode($keyword) . '&x=' . $x . '&y=' . $y);
                    $array = json_decode($str);
                    $map = array();
                    foreach ($array as $key => $vo) {
                        $map[] = array($vo->title, $key, rtrim($this->siteUrl, '/') . '/tpl/static/images/home.jpg', $vo->url);
                    }
                    if ($map) {
                        return array($map, 'news');
                    } else {
                        return array('附近信息无法调出，请稍候再试一下（关键词' . $keyword . ',坐标：' . $x . '-' . $y . ')', 'text');
                    }
                }
            } else {
                $amamp = new amap();
                return $amamp->around($x, $y, $keyword, $radius);
            }
        } else {
            if (!(strpos($this->fun, 'lbsNews') === FALSE)) {
                $lbsImgClass = new lbsImgNews($this->token, $this->data['FromUserName'], $this->siteUrl);
                return $lbsImgClass->news($x, $y);
            }
            $mapAction = new Maps($this->token);
            if (!(strpos($user_request_row['keyword'], '开车去') === FALSE) || !(strpos($user_request_row['keyword'], '坐公交') === FALSE) || !(strpos($user_request_row['keyword'], '步行去') === FALSE)) {
                if (!(strpos($user_request_row['keyword'], '步行去') === FALSE)) {
                    $companyid = str_replace('步行去', '', $user_request_row['keyword']);
                    if (!$companyid) {
                        $companyid = 1;
                    }
                    return $mapAction->walk($x, $y, $companyid);
                }
                if (!(strpos($user_request_row['keyword'], '开车去') === FALSE)) {
                    $companyid = str_replace('开车去', '', $user_request_row['keyword']);
                    if (!$companyid) {
                        $companyid = 1;
                    }
                    return $mapAction->drive($x, $y, $companyid);
                }

                if (!(strpos($user_request_row['keyword'], '坐公交') === FALSE)) {
                    $companyid = str_replace('坐公交', '', $user_request_row['keyword']);
                    if (!$companyid) {
                        $companyid = 1;
                    }
                    return $mapAction->bus($x, $y, $companyid);
                }

            } else {
                switch ($user_request_row['keyword']) {
                    default:
                        return $this->companyMap();
                        break;
                    case '最近的':
                        return $mapAction->nearest($x, $y);
                        break;
                }
            }
        }
    }

    //算命

    private function suanming($name)
    {
        $name = implode('', $name);
        if (empty($name)) {
            return '主人' . $this->my . '提醒您正确的使用方法是[算命+姓名]';
        }
        $data = (require_once CONF_PATH . 'suanming.php');
        $num = mt_rand(0, 80);
        return $name . '
' . trim($data[$num]);
    }

    //音乐

    private function yinle($name)
    {
        $thirdAppMusic = new thirdAppMusic($name);
        return $thirdAppMusic->index();
    }

    //歌词
    public function geci($n)
    {
        $name = implode('', $n);
        @($str = 'http://api.ajaxsns.com/api.php?key=free&appid=0&msg=' . urlencode('歌词' . $name));
        $json = json_decode(file_get_contents($str));
        $str = str_replace('{br}', '
', $json->content);
        return str_replace('mzxing_com', 'pigcms', $str);
    }

    //域名

    private function yuming($n)
    {
        $name = implode('', $n);
        $str = 'http://api.ajaxsns.com/api.php?key=free&appid=0&msg=' . urlencode('域名 ' . $name);
        $json = json_decode(file_get_contents($str));
        $str = str_replace('{br}', '', $json->content);
        return str_replace('mzxing_com', 'pigcms', $str);
    }

    //天气

    private function tianqi($n)
    {
        $name = implode('', $n);
        if ($name == '') {
            $name = '北京';
        }
        $s = '';
        $name = str_replace('天气', '', $name);
        $name = mb_convert_encoding($name, 'gb2312', 'UTF-8');
        $content = file_get_contents('http://php.weather.sina.com.cn/xml.php?city=' . $name . '&password=DJOYnieT8234jlsK&day=0');
        $xml = simplexml_load_string($content);
        foreach ($xml as $tmp) {
            $s = '**' . $tmp->city . '天气-今天**
                日期' . $tmp->savedate_weather . '
                白天:' . $tmp->status1 . '
                夜晚:' . $tmp->status2 . '
                温度:' . $tmp->temperature1 . '-' . $tmp->temperature2 . '摄氏度
                风级:' . $tmp->power1 . '
                风向:' . $tmp->direction1 . '
                污染指数:' . $tmp->pollution_l . '
                污染指数说明:' . $tmp->pollution_s . '
                感冒指数:' . $tmp->gm_l . '
                感冒指数说明:' . $tmp->gm_s . '
                紫外线:' . $tmp->zwx_s . '
                洗车指数:' . $tmp->xcz_s . '
                穿衣说明:' . $tmp->chy_shuoming . '
                ************************';
        }
        $content = file_get_contents('http://php.weather.sina.com.cn/xml.php?city=' . $name . '&password=DJOYnieT8234jlsK&day=1');
        $xml = simplexml_load_string($content);
        foreach ($xml as $tmp) {
            $s = $s . '**' . $tmp->city . '天气-明天**
                日期' . $tmp->savedate_weather . '
                白天:' . $tmp->status1 . '
                夜晚:' . $tmp->status2 . '
                温度:' . $tmp->temperature1 . '-' . $tmp->temperature2 . '摄氏度
                风级:' . $tmp->power1 . '
                风向:' . $tmp->direction1 . '
                污染指数:' . $tmp->pollution_l . '
                污染指数说明:' . $tmp->pollution_s . '
                感冒指数:' . $tmp->gm_l . '
                感冒指数说明:' . $tmp->gm_s . '
                紫外线:' . $tmp->zwx_s . '
                洗车指数:' . $tmp->xcz_s . '
                穿衣说明:' . $tmp->chy_shuoming . '
                *********************';
        }
        $content = file_get_contents('http://php.weather.sina.com.cn/xml.php?city=' . $name . '&password=DJOYnieT8234jlsK&day=2');
        $xml = simplexml_load_string($content);
        foreach ($xml as $tmp) {
            $s = $s . '**' . $tmp->city . '天气-后天**
                日期' . $tmp->savedate_weather . '
                白天:' . $tmp->status1 . '
                夜晚:' . $tmp->status2 . '
                温度:' . $tmp->temperature1 . '-' . $tmp->temperature2 . '摄氏度
                风级:' . $tmp->power1 . '
                风向:' . $tmp->direction1 . '
                污染指数:' . $tmp->pollution_l . '
                污染指数说明:' . $tmp->pollution_s . '
                感冒指数:' . $tmp->gm_l . '
                感冒指数说明:' . $tmp->gm_s . '
                紫外线:' . $tmp->zwx_s . '
                洗车指数:' . $tmp->xcz_s . '
                穿衣说明:' . $tmp->chy_shuoming . '
                *********************';
        }
        return $s;
    }

    //手机归属地
    private function shouji($n)
    {
        $name = implode('', $n);
        @($str = 'http://api.ajaxsns.com/api.php?key=free&appid=0&msg=' . urlencode('归属' . $name));
        $json = json_decode(file_get_contents($str));
        $str = str_replace('{br}', '', $json->content);
        $str = str_replace('菲菲', $this->my, str_replace('提示：', $this->my . '提醒您:', str_replace('{br}', '', $str)));
        return $str;
    }

    //身份证

    private function shenfenzheng($n)
    {
        $n = implode('', $n);
        if (count($n) > 1) {
            $this->error_msg($n);
            return false;
        }
        $str1 = file_get_contents('http://www.youdao.com/smartresult-xml/search.s?jsFlag=true&type=id&q=' . $n);
        $array = explode(':', $str1);
        $array[2] = rtrim($array[4], ',\'gender\'');
        $str = trim($array[3], ',\'birthday\'');
        if ($str !== iconv('UTF-8', 'UTF-8', iconv('UTF-8', 'UTF-8', $str))) {
            $str = iconv('GBK', 'UTF-8', $str);
        }
        $str = '【身份证】 ' . $n . '' . '【地址】' . $str . ' 【该身份证主人的生日】' . str_replace('\'', '', $array[2]);
        return $str;
    }

    //公交

    private function gongjiao($data)
    {
        $data = array_merge($data);
        if (count($data) < 2) {
            $this->error_msg('有问题');
            return false;
        }
        if (trim($data[0]) == '' or trim($data[1]) == '') {
            return '公交车查询格式为：上海公交774';
        }
        $json = file_get_contents('http://www.twototwo.cn/bus/Service.aspx?format=json&action=QueryBusByLine&key=5da453b2-b154-4ef1-8f36-806ee58580f6&zone=' . $data[0] . '&line=' . $data[1]);

        $data = json_decode($json);
                //线路名
        $xianlu = $data->Response->Head->XianLu;
        //验证查询是否正确
        $xdata = get_object_vars($xianlu->ShouMoBanShiJian);
        $xdata = $xdata['#cdata-section'];
        $piaojia = get_object_vars($xianlu->PiaoJia);
        $xdata = $xdata . ' -- ' . $piaojia['#cdata-section'];
        $main = $data->Response->Main->Item->FangXiang;
        //线路-路经
        $xianlu = $main[0]->ZhanDian;
        $str = '【本公交途经】
';
        for ($i = 0; $i < count($xianlu); $i++) {
            $str .= '
' . trim($xianlu[$i]->ZhanDianMingCheng);
        }
        return $str;
    }

    //火车

    private function huoche($data, $time = '')

    {

        $data = array_merge($data);
        $data[2] = date('Y', time()) . $time;
        if (count($data) != 3) {
            $this->error_msg($data[0] . '至' . $data[1]);
            return false;
        }

        $time = empty($time) ? date('Y-m-d', time()) : date('Y-', time()) . $time;
        $json = file_get_contents('http://www.twototwo.cn/train/Service.aspx?format=json&action=QueryTrainScheduleByTwoStation&key=5da453b2-b154-4ef1-8f36-806ee58580f6&startStation=' . $data[0] . '&arriveStation=' . $data[1] . '&startDate=' . $data[2] . '&ignoreStartDate=0&like=1&more=0');
        if ($json) {
            $data = json_decode($json);
            $main = $data->Response->Main->Item;
            if (count($main) > 10) {
                $conunt = 10;
            } else {
                $conunt = count($main);
            }

            for ($i = 0; $i < $conunt; $i++) {
                $str .= ' 【编号】' . $main[$i]->CheCiMingCheng . ' 【类型】' . $main[$i]->CheXingMingCheng . '【发车时间】:　' . $time . ' ' . $main[$i]->FaShi . '【耗时】' . $main[$i]->LiShi . ' 小时';
                $str .= '----------------------';
            }

        } else {
            $str = '没有找到 ' . $name . ' 至 ' . $toname . ' 的列车';
        }
        return $str;
    }

    //翻译

    private function fanyi($name)
    {
        $name = array_merge($name);
        $url = 'http://openapi.baidu.com/public/2.0/bmt/translate?client_id=kylV2rmog90fKNbMTuVsL934&q=' . $name[0] . '&from=auto&to=auto';
        $json = Http::fsockopenDownload($url);
        if ($json == false) {
            $json = file_get_contents($url);
        }
        $json = json_decode($json);
        $str = $json->trans_result;
        if ($str[0]->dst == false) {
            return $this->error_msg($name[0]);
        }
        $mp3url = 'http://www.apiwx.com/aaa.php?w=' . $str[0]->dst;
        if (strpos($mp3url, ' ')) {
            return array($name[0] . ':' . $str[0]->dst, 'text');
        } else {
            return array(array($str[0]->src, $str[0]->dst, $mp3url, $mp3url), 'music');
        }
    }

    //彩票

    private function caipiao($name)
    {
        $name = array_merge($name);
        $url = 'http://api2.sinaapp.com/search/lottery/?appkey=0020130430&appsecert=fa6095e113cd28fd&reqtype=text&keyword=' . $name[0];
        $json = Http::fsockopenDownload($url);
        if ($json == false) {
            $json = file_get_contents($url);
        }
        $json = json_decode($json, true);
        $str = $json['text']['content'];
        return $str;
    }

    //解梦
    private function mengjian($name)
    {
        $name = array_merge($name);
        if (empty($name)) {
            return '此梦乃是天机，不能泄露！';
        }
        $url = 'http://www.aosituoma.net/api.php?m=Index&a=getDream&dream=' . urlencode($name[0]);
        $data = file_get_contents($url);
        $rt = json_decode($data, 1);
        return str_replace('<br>', '', $rt['content']);
    }

    //股票
    public function gupiao($name)
    {
        $url = 'http://api2.sinaapp.com/search/stock/?appkey=0020130430&appsecert=fa6095e113cd28fd&reqtype=text&keyword=' . $name[1];
        $json = Http::fsockopenDownload($url);
        if ($json == false) {
            $json = file_get_contents($url);
        }
        $json = json_decode($json, true);
       $str = $json['text']['content'];
        return $str;
    }

    public function getmp3($data)
    {
        $obj = new getYu();
        $ContentString = $obj->getGoogleTTS($data);
        $randfilestring = 'mp3/' . time() . '_' . sprintf('%02d', rand(0, 999)) . '.mp3';
        return rtrim($this->siteUrl, '/') . $randfilestring;
    }

    //笑话
    public function xiaohua($n)  {
        $name = implode('', $n);
        @($str = 'http://www.tuling123.com/openapi/api?key=' . C('server_key') . '&info=' . urlencode('笑话' . $name));
        $json = json_decode(file_get_contents($str));
        $str = str_replace('{br}', '
', $json->content);
        return str_replace(array('mzxing_com', '提示：按分类看笑话请发送“笑话分类”'), array('pigcms', ''), $str);
    }

    //聊天

    private function liaotian($name)
    {
        $name = array_merge($name);
        $this->chat($name[0]);
    }
    //聊天
    private function chat($name)
    {

        $function = M('Function')->where(array('funname' => 'liaotian'))->find();
        if (!$function['status']) {
            return '';
        }

        $this->requestdata('textnum');
        $check = $this->user('connectnum');//查看功能性请求是否用完
        if ($check['connectnum'] != 1) {
            return C('connectout');
        }
        if (!(strpos($name, '你是') === FALSE)) {
            return '咳咳，我是智能微信机器人';
        }
        if ($name == '你叫什么' || $name == '你是谁') {
            return '咳咳，我是聪明与智慧并存的美女，主人你可以叫我' . $this->my . ',人家刚交男朋友,你不可追我啦';
        } elseif ($name == '你父母是谁' || $name == '你爸爸是谁' || $name == '你妈妈是谁') {
            return '主人,' . $this->my . '是您创造的,所以他们是我的父母,不过主人我属于你的';
        } elseif ($name == '糗事') {
            $name = '笑话';
        } elseif ($name == '网站' || $name == '官网' || $name == '网址' || $name == '3g网址') {
            return '【' . C('site_name') . '】' . C('site_name') . '【' . C('site_name') . '服务宗旨】化繁为简,让菜鸟也能使用强大的系统!';
        }
        $str = 'http://www.tuling123.com/openapi/api?key=' . C('server_key') . '&info=' . urlencode($name);
        $json = Http::fsockopenDownload($str);
        if ($json == false) {
            $json = file_get_contents($str);
        }

        $json = json_decode($json, true);
        $str = str_replace('菲菲', $this->my, str_replace('提示：', $this->my . '提醒您:', str_replace("<br>", "\n", $json['text'])));
        return str_replace('mzxing_com', 'pigcms', $str);
    }


    //帮助
    private function help()
    {
        $this->behaviordata('help', '', '1');
        $data = M('Areply')->where(array('token' => $this->token))->find();
        if (!$data || !$data['content']) {
            $data = array('content' => '恭喜您，接入成功');
        }
        return array(preg_replace('/()|()|()/', '', $data['content']), 'text');
    }

    /**
     * 找不到的情况下报错
     * @param $data
     * @return string
     */
    private function error_msg($data)
    {
        return '没有找到' . $data . '相关的数据';
    }

    //查询用户的请求次数是否超过组的限制
    private function user($action)
    {        //查询微信号
        $user = $this->wxuser;
         //公共条件
        $dataarray = array('id' => $user['uid']);
        //用户信息
        $users = $this->user;
        //用户组
        $group = M('User_group')->where(array('id' => $users['gid']))->find();
        if ($users['diynum'] < $group['diynum']) {
            $data['diynum'] = 1;
            if ($action == 'diynum') {
            }
        }
        if ($users['connectnum'] < $group['connectnum']) {
            $data['connectnum'] = 1;
            if ($action == 'connectnum') {
                $usersdata = M('Users');
                $usersdata->where($dataarray)->setInc('connectnum');
            }
        }
        if ($users['viptime'] > time()) {
            $data['viptime'] = 1;
        }
        return $data;
    }

	//获取access_token

	 public static function getAccessToken() {
    // access_token 应该全局存储与更新，获取本地存储的到服务器上进行验证，如果出现错误不管时间是否大于7000秒都强制刷新
    $data = json_decode(file_get_contents("GongDan/access_token.json"));
    $test=curlGet('https://api.weixin.qq.com/cgi-bin/material/get_materialcount?access_token='.$data->access_token);
    if ($data->expire_time < time()||strstr($test,'errcode')) {
      $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.C('site_appId').'&secret='.C('site_appSecret').'';
        $res =curlGet($url);
	    $arr = json_decode($res, true);
     $access_token = $arr['access_token'];
      if ($access_token) {
        $data->expire_time = time() + 7000;
        $data->access_token = $access_token;
        $fp = fopen("GongDan/access_token.json", "w");
        fwrite($fp, json_encode($data));
        fclose($fp);
      }

    } else {
      $access_token = $data->access_token;
    }
    return $access_token;
  }


    //记录请求信息统计

    /**记录各种类型的请求次数 只记录数量，不记录行为 只分为文字、视频、图片等 每个用户每天一行，记录当天的请求次数
     * @param $field 字段名
     */
    private function requestdata($field)
    {
        $data['year'] = date('Y');
        $data['month'] = date('m');
        $data['day'] = date('d');
        $data['token'] = $this->token;
        $mysql = M('Requestdata');
        $check = $mysql->field('id')->where($data)->find();
        if ($check == false) {
            $data['time'] = time();
            $data[$field] = 1;
            $mysql->add($data);
        } else {
            $mysql->where($data)->setInc($field);
        }
    }

    //把用户的行为记录到behavior表里 如果同一天发多次，记录num作为次数 这种的model
    private function behaviordata($field, $id = '', $type = '')
    {
        $data['date'] = date('Y-m-d', time());
        $data['token'] = $this->token;
        $data['openid'] = $this->data['FromUserName'];
        $data['keyword'] = $this->data['Content'];
        if (!$data['keyword']) {
            $data['keyword'] = '用户关注';
        }

        $data['model'] = $field;
        if ($id != false) {
            $data['fid'] = $id;
        }

        if ($type != false) {
            $data['type'] = 1;
        }

        $mysql = M('Behavior');
        $check = $mysql->field('id')->where($data)->find();
        $this->updateMemberEndTime($data['openid']);
        if ($check == false) {
            $data['num'] = 1;
            $data['enddate'] = time();
            $mysql->add($data);
        } else {
            $mysql->where($data)->setInc('num');
        }
    }

    //记录用户最后的请求时间
    private function updateMemberEndTime($openid)
    {
        $mysql = M('Wehcat_member_enddate');
        $id = $mysql->field('id')->where(array('openid' => $openid))->find();
        $data['enddate'] = time();
        $data['openid'] = $openid;
        $data['token'] = $this->token;
        if ($id == false) {
            $mysql->add($data);
        } else {
            $data['id'] = $id['id'];
            $mysql->save($data);
        }
    }

    //选择、调用在线客服
    private function selectService()
    {
        if (!C('without_chat')) {
            $this->behaviordata('chat', '');
            $sepTime = 30 * 60;
            $nowTime = time();
            $time = $nowTime - $sepTime;
            $where['token'] = $this->token;
            //测试客服是在线
            $serviceUserWhere = array('token' => $this->token, 'status' => 0);
            $serviceUserWhere['endJoinDate'] = array('gt', $time);
            $serviceUser = M('Service_user')->field('id')->where($serviceUserWhere)->select();
            if ($serviceUser != false) {
                //检测是否记录粉丝信息
                $list = M('wechat_group_list')->field('id')->where(array('openid' => $this->data['FromUserName'], 'token' => $this->token))->find();
                if ($list == false) {
                    $this->adddUserInfo();
                }

                //检测是否有客服接入
                $serviceJoinDate = M('wehcat_member_enddate')->field('id,uid,joinUpDate')->where(array('token' => $this->token, 'openid' => $this->data['FromUserName']))->find();
                if ($serviceJoinDate['uid'] == false || $nowTime - $serviceJoinDate['joinUpDate'] > $sepTime) {
                    foreach ($serviceUser as $key => $users) {
                        $user[] = $users['id'];
                    }

                    //处理是否有多个客服在线

                    if (count($user) == 1) {
                        $id = $user[0];
                    } else {
                        $rand = mt_rand(0, count($user) - 1);
                        $id = $user[$rand];
                    }

                    //分配客服接入

                    $where['id'] = $serviceJoinDate['id'];
                    $where['uid'] = $id;
                    M('wehcat_member_enddate')->data($where)->save();

                } else {

                     die;
                }
            }
        }
    }

    //百科

    private function baike($name)
    {
        $name = implode('', $name);
        if ($name == 'pigcms') {
            return '世界上最牛B的微信营销系统，两天前被腾讯收购，当然这只是一个笑话';
        }
        $name_gbk = iconv('utf-8', 'gbk', $name);
        //将字符转换成GBK编码，若文件为GBK编码可去掉本行
        $encode = urlencode($name_gbk);
        //对字符进行URL编码
        $url = 'http://baike.baidu.com/list-php/dispose/searchword.php?word=' . $encode . '&pic=1';
        $get_contents = $this->httpGetRequest_baike($url);
        //获取跳转页内容
        $get_contents_gbk = iconv('gbk', 'utf-8', $get_contents);
        //将获取的网页转换成UTF-8编码，若文件为GBK编码可去掉本行
        preg_match('/URL=(\\S+)\'>/s', $get_contents_gbk, $out);
        //获取跳转后URL
        $real_link = 'http://baike.baidu.com' . $out[1];

        $get_contents2 = $this->httpGetRequest_baike($real_link);
        //获取跳转页内容
        preg_match('#"Description"\\scontent="(.+?)"\\s\\/\\>#is', $get_contents2, $matchresult);
        if (isset($matchresult[1]) && $matchresult[1] != '') {
            return htmlspecialchars_decode($matchresult[1]);
        } else {
            return '抱歉，没有找到与“' . $name . '”相关的百科结果。';
        }

    }

    //获取带参数二维码的数据 把带参数的二维码中的关键词读出来
    private function getRecognition($id)
    {
        $GetDb = D('Recognition');
        $data = $GetDb->field('keyword')->where(array('id' => $id, 'status' => 0))->find();
        if ($data != false) {
            $GetDb->where(array('id' => $id))->setInc('attention_num');
            return $data['keyword'];
        } else {
            return false;
        }

    }

    //第三方接口
    private function api_notice_increment($url, $data, $converturl = 1, $xmlmode = 1)

    {
        $ch = curl_init();
        $header = 'Accept-Charset: utf-8';
        if ($converturl) {
            if (strpos($url, '?')) {
                $url .= '&token=' . $this->token;
            } else {
                $url .= '?token=' . $this->token;
            }
        }

        if ($xmlmode) {
            $headers = array('User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:14.0) Gecko/20100101 Firefox/14.0.1', 'Accept-Language: en-us,en;q=0.5', 'Referer:http://mp.weixin.qq.com/', 'Content-type: text/xml');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $tmpInfo = curl_exec($ch);
        if (curl_errno($ch)) {
            return false;
        } else {
            return $tmpInfo;
        }

    }

    private function httpGetRequest_baike($url)
    {
        $headers = array('User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:14.0) Gecko/20100101 Firefox/14.0.1', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-us,en;q=0.5', 'Referer: http://www.baidu.com/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        curl_close($ch);
        if ($output === FALSE) {
            return 'cURL Error: ' . curl_error($ch);
        }
        return $output;
    }

    //获取用户信息及组信息并保存到表 ie_wechat_group_list中
    private function adddUserInfo()
    {
        $access_token = $this->getAccessToken();
        $url2 = 'https://api.weixin.qq.com/cgi-bin/user/info?openid=' . $this->data['FromUserName'] . '&access_token=' . $access_token;
        $classData = json_decode($this->curlGet($url2));
        $db = M('wechat_group_list');
        $data['token'] = $this->token;
        $data['openid'] = $this->data['FromUserName'];
        $item = $db->where(array('token' => $this->token, 'openid' => $this->data['FromUserName']))->find();
        $data['nickname'] = str_replace('\'', '', $classData->nickname);
        $data['sex'] = $classData->sex;
        $data['city'] = $classData->city;
        $data['province'] = $classData->province;
        $data['headimgurl'] = $classData->headimgurl;
        $data['subscribe_time'] = $classData->subscribe_time;
        $url3 = 'https://api.weixin.qq.com/cgi-bin/groups/getid?access_token=' . $access_token;
        $json = json_decode($this->curlGet($url3, 'post', '{"openid":"' . $data['openid'] . '"}'));
        $data['g_id'] = $json->groupid;
        if (!$data['g_id']) {
            $data['g_id'] = 0;
        }

        if (!$item) {
            $db->data($data)->add();
        } else {
            $db->where(array('token' => $this->token, 'openid' => $this->data['FromUserName']))->save($data);
        }

    }

      /**
      * 远程获取内容
     * @param $url
     * @param string $method
     * @param string $data
     * @return mixed
     */
    private static function curlGet($url, $method = 'get', $data = '')

    {
        $ch = curl_init();
        $header = 'Accept-Charset: utf-8';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $temp = curl_exec($ch);
        return $temp;
    }

    //中文分词
    private function get_tags($title, $num = 10)
    {
        vendor('Pscws.Pscws4', '', '.class.php');
        $pscws = new PSCWS4();
        $pscws->set_dict(CONF_PATH . 'etc/dict.utf8.xdb');
        $pscws->set_rule(CONF_PATH . 'etc/rules.utf8.ini');
        $pscws->set_ignore(true);
        $pscws->send_text($title);
        $words = $pscws->get_tops($num);
        $pscws->close();
        $tags = array();
        foreach ($words as $val) {
            $tags[] = $val['word'];
        }
        return implode(',', $tags);
    }

    public function handleIntro($str)
    {
        $str = html_entity_decode(htmlspecialchars_decode($str));
        $search = array('&amp;', '&quot;', '&nbsp;', '&gt;', '&lt;');
        $replace = array('&', '"', ' ', '>', '<');
        return strip_tags(str_replace($search, $replace, $str));
    }

    //照片墙
     public function zhaopianwall($zhaopianwall_result)
    {
        $message = $this->data;
        $zhaopianwall_name = '';
        if ($message['MsgType'] == 'text') {
            $zhaopianwall_name = $message['Content'];
        }

        //取消直接删除缓存

        if ($zhaopianwall_name == '取消') {
            S('zhaopianwall_' . $this->data['FromUserName'], NULL);
            return array('晒图片取消成功！感谢您的参与', 'text');
        } else {
            S('zhaopianwall_' . $this->data['FromUserName'], NULL);
            $zhaopianwall_result['username'] = $zhaopianwall_name;
            $pic_wall_inf = M('pic_wall')->where(array('token' => $this->token, 'id' => $zhaopianwall_result['uid']))->order('id desc')->find();
            M('pic_walllog')->data($zhaopianwall_result)->add();
            if ($zhaopianwall_result['state']) {
                //照片上传成功
                $info = M('pic_walllog')->where(array('token' => $this->token, 'wecha_id' => $this->data['FromUserName']))->order('create_time desc')->limit(1)->find();
                return array(array(array('照片上墙成功', '点击进入照片墙！', $info['picurl'], $this->siteUrl . '/index.php?g=Wap&m=Zhaopianwall&a=index&token=' . $this->token . '&wecha_id=' . $this->data['FromUserName'] . '&sgssz=mp.weixin.qq.com')), 'news');
            } else {
                //照片需要审核
                return array('照片上传成功，正在审核，审核通过后可以显示', 'text');
            }
        }
    }

    private function data2xml($xml, $data, $item = 'item') {
        foreach ($data as $key => $value) {
            /* 指定默认的数字key */
            is_numeric($key) && $key = $item;
            /* 添加子元素 */
            if (is_array($value) || is_object($value)) {
                $child = $xml->addChild($key);
                $this->data2xml($child, $value, $item);
            }else {
                if (is_numeric($value)) {
                    $child = $xml->addChild($key, $value);
                }else {
                    $child = $xml->addChild($key);
                    $node = dom_import_simplexml($child);
                    $node->appendChild($node->ownerDocument->createCDATASection($value));
                }
            }
        }
    }

    public function getaccess(){
        echo $this->getAccessToken();
            }
}