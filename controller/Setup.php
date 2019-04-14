<?php
use \Xpmse\Loader\App as App;
use \Xpmse\Utils as Utils;
use \Xpmse\Tuan as Tuan;
use \Xpmse\Excp as Excp;
use \Xpmse\Conf as Conf;
use \Xpmse\Option as Option;
use \Xpmse\Wechat as Wechat;


class SetupController extends \Xpmse\Loader\Controller {
	
	function __construct() {

		$this->models = [
            '\\Xpmsns\\Comment\\Model\\Agree',
            '\\Xpmsns\\Comment\\Model\\Comment',
		];
	}


	/**
	 * 初始化用户相关配置项
	 * @return 
	 */
	private function init_option() {

		// // 注册微信消息处理器
		// Wechat::bind("xpmsns/user", "user/wechatRouter");

		// $opt = new Option('xpmsns/message');

		// // 短信验证码
		// $sms_vcode = $opt->get("user/sms/vcode");
		// if ( $sms_vcode === null ) {
		// 	$opt->register(
		// 		"短信验证码配置", 
		// 		"user/sms/vcode", 
		// 		[
		// 			"type" => "qcloud",
		// 			"option"=>[
		// 				"appid" => "<your appid>",
		// 				"appkey" => "<your appkey>",
		// 				"sign" => "您的签名",
		// 				"message" => "您的短信验证码为 {1} , 打死不要告诉别人！" 
		// 			]
		// 		],
		// 		90
		// 	);
        // }
    
	}


	private  function remove_option(){
		$opt = new Option('xpmsns/message');
		$opt->unregister();
		// 解绑微信处理器
		Wechat::unbind("xpmsns/message");
	}

	
	private  function init_group() {
		
		$g = new \Xpmsns\User\Model\Group;
		$default = $g->getBySlug('default');
		if ( empty($default) ) {
			$g->create(['slug'=>"default", 'name'=>'默认分组']);
		}
	}


	function install() {

		$models = $this->models;
		$insts = [];
		foreach ($models as $mod ) {
			try { $insts[$mod] = new $mod(); } catch( Excp $e) {echo $e->toJSON(); return;}
		}
		
		foreach ($insts as $inst ) {
			try { $inst->__clear(); } catch( Excp $e) {echo $e->toJSON(); return;}
            try { $inst->__schema(); } catch( Excp $e) {echo $e->toJSON(); return;}
            try { $inst->__defaults(); } catch( Excp $e) { echo $e->toJSON(); return; }  // 加载默认数据
		}

		// 创建配置项
		try {
			$this->init_option();
		} catch( Excp $e ){
			echo $e->toJSON(); return;
		}

		// 创建默认分组
		try {
			$this->init_group();
		} catch( Excp $e ){
			echo $e->toJSON(); return;
		}

		echo json_encode('ok');
	}


	function upgrade(){
		echo json_encode('ok');	
	}

	function repair() {

		$models = $this->models;
		$insts = [];
		foreach ($models as $mod ) {
			try { $insts[$mod] = new $mod(); } catch( Excp $e) {echo $e->toJSON(); return;}
		}
		
		foreach ($insts as $inst ) {
            try { $inst->__schema(); } catch( Excp $e) {echo $e->toJSON(); return;}
            try { $inst->__defaults(); } catch( Excp $e) { echo $e->toJSON(); return; }  // 加载默认数据
		}

		// 创建配置项
		try {
			$this->init_option();
		} catch( Excp $e ){
			echo $e->toJSON(); return;
		}

		// 创建默认分组
		try {
			$this->init_group();
		} catch( Excp $e ){
			echo $e->toJSON(); return;
		}

		echo json_encode('ok');		
	}


	// 卸载
	function uninstall() {

		$models = $this->models;
		$insts = [];
		foreach ($models as $mod ) {
			try { $insts[$mod] = new $mod(); } catch( Excp $e) {echo $e->toJSON(); return;}
		}
		
		foreach ($insts as $inst ) {
			try { $inst->__clear(); } catch( Excp $e) {echo $e->toJSON(); return;}
		}


		// 移除配置项
		try {
			$this->remove_option();
		} catch( Excp $e ){
			echo $e->toJSON(); return;
		}



		echo json_encode('ok');		
	}
}