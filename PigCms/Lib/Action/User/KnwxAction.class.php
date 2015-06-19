<?php
class KnwxAction extends UserAction{
    public function _initialize() {
		parent::_initialize();
		$function=M('Function')->where(array('funname'=>'Knwx'))->find();
		$this->canUseFunction('Knwx');
	}  
	public function index(){
	
		$where['token'] = session('token');
		$Cdata = M('Knwxreplay');
		$info = $Cdata->where($where)->find();
		$this->info = $info;
		if(IS_POST){
			$where['token'] = session('token');
			$data['pic'] = strip_tags($_POST['pic']);
			$data['title'] = strip_tags($_POST['title']);
			$data['keyword'] = strip_tags($_POST['keyword']);
			$data['jianjie'] = strip_tags($_POST['jianjie']);
			$data['gzlj'] = strip_tags($_POST['gzlj']);
			$data['adpic'] = strip_tags($_POST['adpic']);
			$data['adurl'] = strip_tags($_POST['adurl']);
			
$data['banquan'] = strip_tags($_POST['banquan']);
			$data['open'] = strip_tags($_POST['open']);
			$data['mypicurl1'] = strip_tags($_POST['mypicurl1']);
			if($info){
				$result = M('Knwxreplay')->where($where)->save($data);
				if($result){
					$res=M('Knwxreplay')->where($where)->find();
				    $data1['pid']=$res['id'];
                    $data1['module']='Knwx';
                    $data1['token']=session('token');
                    $data1['keyword']=$_POST['keyword'];
					$where['module']='Knwx';
					$res=M('keyword')->where(array('module'=>'Knwx','token'=>session('token')))->find();
					if($res)
                    M('keyword')->where($where)->save($data1);
					else M('keyword')->add($data1);
					$this->success('回复信息更新成功!');
				}else{
					$this->error('服务器繁忙 更新失败!');
				}
			}else{
				$data['token'] = session('token');
				$insert = M('Knwxreplay')->add($data);
				if($insert > 0){
					$this->success('回复信息添加成功!');
				}else{
					$this->error('回复信息添加失败!');
				}
			}
		}else{
			$this->display();
		}
	
	}
	
	
}



?>