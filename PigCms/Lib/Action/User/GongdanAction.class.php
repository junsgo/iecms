<?php
class GongdanAction extends UserAction{

	public function index(){
		$token=$_GET['token'];
		//$info=M('queue')->where(array('token'=>$token))->order('number ASC ')->select();
		
		
		//
		$statue=$this->_get('statue','intval');
		$znfl=$_GET['znfl'];
		$condition['token']=$token;
		if($znfl){
			$condition['znll']=$znfl;
			
			}
			
		//0 1 以假乱真
		if($statue){
		    if($statue=='3'){
			$condition['statue']='0';
			}else{
			$condition['statue']=$_GET['statue'];}
			
			}
			
	        $count=M('queue')->where($condition)->order('number ASC ')->count();
		    $page=new Page($count,10);
		
			$info=M('queue')->where($condition)->order('number ASC ')->limit($page->firstRow.','.$page->listRows)->select();
			//dump($count);exit;
		
		$this->assign('info',$info);
		$total=M('queue')->where(array('token'=>$token))->count();//用户统计
		$this->assign('total',$total);
		$total_0=M('queue')->where(array('token'=>$token,'statue'=>0))->count();
		$this->assign('total_0',$total_0);
		$total_1=M('queue')->where(array('token'=>$token,'statue'=>1))->count();
		$this->assign('total_1',$total_1);
		$total_2=M('queue')->where(array('token'=>$token,'statue'=>2))->count();
		$this->assign('total_2',$total_2);
		$this->assign('token',$token);
		$this->assign('page',$page->show());
		//评价统计
		$pj_1=M('queue_evaluation')->where(array('token'=>$token,'xx'=>1))->count();
		$pj_2=M('queue_evaluation')->where(array('token'=>$token,'xx'=>2))->count();
		$pj_3=M('queue_evaluation')->where(array('token'=>$token,'xx'=>3))->count();
		$this->assign('pj_3',$pj_3);
		$this->assign('pj_2',$pj_2);
		$this->assign('pj_1',$pj_1);
			$this->display();
		
		
	}
	public function detail(){
		
		 $count=M('queue_ansermsg')->where(array('token'=>$_GET['token'],'wecha_id'=>$_GET['wecha_id']))->count();
		    $page=new Page($count,10);
		
		$infos=M('queue')->where(array('token'=>$_GET['token'],'wecha_id'=>$_GET['wecha_id']))->find();
		$info=M('queue_ansermsg')->where(array('token'=>$_GET['token'],'wecha_id'=>$_GET['wecha_id']))->limit($page->firstRow.','.$page->listRows)->order('time desc')->select();
		
		$this->assign('infos',$infos);
		$this->assign('info',$info);
		$this->assign('page',$page->show());
		$this->display();
		}
	public function set(){
		$token=$_GET['token'];
		$info=M('queue_set')->where(array('token'=>$token))->find();
		if($_POST){
		$date['token']=$_GET['token'];
		$date['statue']=$_POST['statue'];
		$date['openid']=$_POST['openid'];
		$date['head']=$_POST['head'];
		$date['name']=$_POST['name'];
		$date['zdhf']=$_POST['zdhf'];
		$date['czopenid']=$_POST['czopenid'];
		$date['mqopenid']=$_POST['mqopenid'];
		
		if(empty($info)){
		      $a=M('queue_set')->add($date);
		                   if($a){
			                          $this->success('添加成功');exit;
			
			                      }
						else{$this->error('添加失败');exit;}
		
		
		
		}else{
		$as=M('queue_set')->where(array('token'=>$token))->save($date);
		if($as){
			$this->success('更新成功');exit;
			}	else{$this->error('更新失败');exit;}
			
			
			}}
			
			$this->assign('info',$info);
		$this->display();
	
	
		}
	public function statue(){
		$infos=M('queue')->where(array('token'=>$_GET['token'],'wecha_id'=>$_GET['wecha_id'],'id'=>$_GET['id']))->find();
		$token=$_GET['token'];
		$wecha_id=$_GET['wecha_id'];
		$statue=$_GET['statue'];
		$date['statue']=$statue;
		
		
		if($infos){
			 if($statue=='1'){
			/* $txt='{
                  "touser":"'.$wecha_id.'",
                  "msgtype":"text",
                  "text":
                   {
                    "content":"尊敬的微乾隆商户:
'.$infos['name'].'你好，微乾隆技术已经开始为您处理工单了，请您保持微信在线，微乾隆技术将与您联系！"
                  }
              }';*/
			
$jsonText='{"touser":"'.$wecha_id.'","template_id":"xHBv8B3th2HrF_aFqO0lme3gMRS8F1Bkqs8TaGUoFn0","url":"","topcolor":"#FF0000","data":{"serviceInfo": {"value":"您好，您的工单已经开始受理！","color":"#173177"},"serviceType": {"value":"微乾隆CMS工单处理","color":"#FF0033"},"serviceStatus": {"value":"受理中......","color":"#FF0033"},"time": {"value":"'.$infos['time']=date('Y年m月d日 H时i分秒',$infos['time']).'","color":"#FF0033"},"remark": {"value":"您的工单已经开始受理了，请您保持在线，及时回答客服的的回复！","color":"#173177"}}}';
		 
		   $txt=$jsonText;
		 
		 
         $dates['dotime1']=time();
		//dump( $txt);exit;
		
		 $dotime=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id,'id'=>$_GET['id']))->save($dates);
			}elseif($statue=='2')
			  {
				  
			
				  $txt='{"touser":"'.$wecha_id.'","template_id":"xHBv8B3th2HrF_aFqO0lme3gMRS8F1Bkqs8TaGUoFn0","url":"","topcolor":"#FF0000","data":{"serviceInfo": {"value":"您好，您的工单已经处理完毕！","color":"#173177"},"serviceType": {"value":"微乾隆CMS工单处理","color":"#FF0033"},"serviceStatus": {"value":"工单处理完毕","color":"#FF0033"},"time": {"value":"'.$infos['time']=date('Y年m月d日 H时i分秒',$infos['time']).'","color":"#FF0033"},"remark": {"value":"您的工单已经处理完毕，感谢您对微乾隆一直的支持与信任！微乾隆与您同在！请对微乾隆的服务做出评价.1：服务非常棒！2：服务一般般拉！3:服务不满意！","color":"#173177"}}}';
				  //受理完毕处理机制
				
         $datess['dotime2']=time();
		 $dotime=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id,'id'=>$_GET['id']))->save($datess);
		 $pj['token']=$token;
		 $pj['wecha_id']=$wecha_id;
		 $pj['statue']=0;
$pjs=M('queue_evaluation')->add($pj);
//处理完毕后 通知后3位用户
                $theuser=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id,'id'=>$_GET['id']))->getField('time');
				
                $txt1_condition['time'] = array('gt', $theuser);
				
                $txt1_condition['statue'] = array('neq', 2);
                $txt1_condition['token'] =$this->token;
			
 $txt1_user=M('queue')->where($txt1_condition)->order('time asc')->limit(0,1)->select();
 



$txt1='{"touser":"'.$txt1_user[0]['wecha_id'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0033","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$txt1_user[0]['number'].'号","color":"#FF0033"},"keyword3": {"value":"0位","color":"#FF0033"},"remark": {"value":"尊敬的'.$txt1_user[0]['name'].'下一位就轮到您了，请您做好准备，等待客服的接入！","color":"#173177"}}}';



$txt2_user=M('queue')->where($txt1_condition)->order('time asc')->limit(1,1)->select();
$txt2='{"touser":"'.$txt2_user[0]['wecha_id'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0033","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$txt2_user[0]['number'].'号","color":"#FF0033"},"keyword3": {"value":"1位","color":"#FF0033"},"remark": {"value":"尊敬的'.$txt2_user[0]['name'].',您的前面还有1位用户，请您耐心等待！","color":"#173177"}}}';

$txt3_user=M('queue')->where($txt1_condition)->order('time asc')->limit(2,1)->select();
$txt2='{"touser":"'.$txt3_user[0]['wecha_id'].'","template_id":"hqpZuM0yyrBFGKWbcKKDafgr9UXiBYapqRE2pTt8tok","url":"","topcolor":"#FF0033","data":{"keyword1": {"value":"微乾隆CMS","color":"#FF0033"},"keyword2": {"value":"'.$txt3_user[0]['number'].'号","color":"#FF0033"},"keyword3": {"value":"2位","color":"#FF0033"},"remark": {"value":"尊敬的'.$txt3_user[0]['name'].',您的前面还有2位用户，您可以稍作休息，等待微乾隆客服处理您的问题，感谢您的等候！","color":"#173177"}}}';









//处理完毕
}
		
			$access_token=$this->curlGet('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.C('site_appId').'&secret='.C('site_appSecret').'');

	$jsonrt=json_decode($access_token,1);

	$url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$jsonrt['access_token'].'';
	
	
	
	$info=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id,'id'=>$_GET['id']))->save($date);	
	
	$result=$this->https_post($url,$txt); 
	
	
$lenth=M('queue')->where($txt1_condition)->order('time asc')->count();
if($lenth <= 3){
	$lenth=$lenth;
	
	}else{
		$lenth=3;
		
		
		}//限制发三条
if($lenth!=='0'){
 
if($statue!=='1'){
for($i=1;$i<$lenth;$i++){
	
$key = 'result'.$i;

$txt = 'txt'.$i;

$$key = $this->https_post($url,$$txt);


}
	 
}}
	
	
			if($statue==2)
			{ $statues='3';}
			     elseif($statue==1){$statues='1' ;}
			
	
			$this->success('更新状态成功',U('Gongdan/index',array('token'=>$this->token,'statue'=>$statues)));	
				}
			
			else
			{
			 $this->error('更改状态失败',U('Gongdan/index',array('token'=>$this->token)));	
				
				}
		$this->assign('info',$info);
			
			
	}
	//回复
	public function notice(){
		
	$token=$_GET['token'];
	$wecha_id=$_GET['openid'];
		$info=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->find();
		$this->assign('info',$info);
   $headset=M('queue_set')->where(array('token'=>$token))->find();
	$content=$_POST['text']	;
		if($_POST){
		$txt='{
                  "touser":"'.$wecha_id.'",
                  "msgtype":"text",
                  "text":
                   {
                    "content":"【客服—'.$headset['name'].'】'.$content.'"
                  }
              }';	
			$access_token=$this->curlGet('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.C('site_appId').'&secret='.C('site_appSecret').'');
	
	$jsonrt=json_decode($access_token,1);
	
	//dump($txt);exit;
	$url='https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token='.$jsonrt['access_token'].'';
	
	$result=$this->https_post($url,$txt); 
	
	
	if($result){
		
		$date['msg']=$content;
		$date['time']=time();
		$date['token']=$token;
		$date['wecha_id']=$wecha_id;
		$date['time']=time();
		$date['user']='【客服—'.$headset['name'].'】';
		$date['head']=$headset['head'];
		$date['from']='kefu';//消息来源记录
		
		$infos=M('queue_ansermsg')->add($date);
		$this->success('发送给【'.$info['name'].'】的消息下发成功',U('Gongdan/detail',array('token'=>$this->token,'wecha_id'=>$wecha_id)));	exit;
		}else{
			
			 $this->error('发送失败',U('Gongdan/detail',array('token'=>$this->token,'wecha_id'=>$wecha_id)));exit;
			
			}
			
			
			}
		 $this->display();
	}
	public function notices(){
		
	$token=$_GET['token'];
	$wecha_id=$_GET['openid'];
		$info=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->find();
		$this->assign('info',$info);
   
	$content=$_POST['text']	;
		if($_POST){
		$txt='{
                  "touser":"'.$wecha_id.'",
                  "msgtype":"text",
                  "text":
                   {
                    "content":"'.$content.'"
                  }
              }';	
			$access_token = $this->_getAccessToken();
	
	//dump($txt);exit;
	$url='https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token='.$access_token.'';
	
	$result=$this->https_post($url,$txt); 
	
	
	if($result){
		$date['msg']=$content;
		$date['time']=time();
		$date['token']=$token;
		$date['wecha_id']=$wecha_id;
		
		$infos=M('queue_msg')->add($date);
		$this->success('发送给【'.$info['name'].'】的消息下发成功',U('Gongdan/lists',array('token'=>$this->token)));	exit;
		}else{
			
			 $this->error('发送失败',U('Gongdan/lists',array('token'=>$this->token)));exit;
			
			}
			
			
			}
		 $this->display();
	}
	public function del(){
		$token=$_GET['token'];
		$wecha_id=$_GET['wecha_id'];
		$id=$_GET['id'];
		
		$info=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id,'id'=>$id))->delete();
		if($info){
			$info=M('queue_ansermsg')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->delete();//问答
			$info=M('queue_msg')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->delete();//通知
			
			
		$this->success('删除工单记录成功');		
			
			}else{
				
				$this->error('删除工单记录失败');	
				}
		
		
		
	}
	public function lists(){	
	
	 $token=$_GET['token'];
		//$info=M('queue')->where(array('token'=>$token))->order('number ASC ')->select();
		$count=M('queue')->where(array('token'=>$token))->order('number ASC ')->count();
		$page=new Page($count,10);
		$info=M('queue')->where(array('token'=>$token))->order('number ASC ')->limit($page->firstRow.','.$page->listRows)->select();
		$this->assign('info',$info);
		$total=M('queue')->where(array('token'=>$token))->count();//用户统计
		$this->assign('total',$total);
		$total_0=M('queue')->where(array('token'=>$token,'statue'=>0))->count();
		$this->assign('total_0',$total_0);
		$total_1=M('queue')->where(array('token'=>$token,'statue'=>1))->count();
		$this->assign('total_1',$total_1);
		$total_2=M('queue')->where(array('token'=>$token,'statue'=>2))->count();
		$this->assign('total_2',$total_2);
		$counts=M('queue_msg')->where(array('token'=>$token))->count();
		$this->assign('counts',$counts);
		$this->assign('page',$page->show());
			$this->display();
	}
	public function msgrecord(){
		$token=$_GET['token'];
		$wecha_id=$_GET['wecha_id'];
	
		$count=M('queue_msg')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->order('time desc ')->count();
		
		$page=new Page($count,10);
		$info=M('queue_msg')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->order('time desc ')->limit($page->firstRow.','.$page->listRows)->select();
		$infos=M('queue')->where(array('token'=>$token,'wecha_id'=>$wecha_id))->find();
		$this->assign('page',$page->show());
		$this->assign('info',$info);
		$this->assign('count',$count);
		$this->assign('infos',$infos);
		 $this->display();
	}	
		
	 function https_post($url, $data){
		$ch = curl_init();
		$header = "Accept-Charset: utf-8";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
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
				$this->error('发生错误：错误代码'.$js['errcode'].',微信返回错误信息：'.$errmsg);
			}
		}
	}   
 function getAccessToken() {
    // access_token 应该全局存储与更新，以下代码以写入到文件中做示例
    $data = json_decode(file_get_contents("GongDan/access_token.json"));
    if ($data->expire_time < time()) {
      $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$this->_appid.'&secret='.$this->_secret.'';
	  
      $res = file_get_contents($url);
     
	    $arr = json_decode($res, true);
     $access_token = $arr['access_token'];
		//dump($access_token );exit;
      if ($access_token) {
        $data->expire_time = time() + 7000;
        $data->access_token = $access_token;
        $fp = fopen("share/access_token.json", "w");
        fwrite($fp, json_encode($data));
        fclose($fp);
      }
    } else {
      $access_token = $data->access_token;
    }
	 
    return $access_token;
  }	
function curlGet($url,$method='get',$data=''){
		$ch = curl_init();
		$header = "Accept-Charset: utf-8";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$temp = curl_exec($ch);
		return $temp;
	}	
protected function http_request($url, $date = null){
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
	if(!empty($data)){
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		
		}
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	$output=curl_exec($url);
	curl_close($curl);
	return $output;	
	
	
	
	}	
	
}


?>