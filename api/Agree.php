<?php
/**
 * Class Agree 
 * 赞同数据接口 
 *
 * 程序作者: XpmSE机器人
 * 最后修改: 2019-01-27 21:32:46
 * 程序母版: /data/stor/private/templates/xpmsns/model/code/api/Name.php
 */
namespace Xpmsns\Comment\Api;
            

use \Xpmse\Loader\App;
use \Xpmse\Excp;
use \Xpmse\Utils;
use \Xpmse\Api;

class Agree extends Api {

	/**
	 * 赞同数据接口
	 */
	function __construct() {
		parent::__construct();
	}

	/**
	 * 自定义函数 
	 */

    // @KEEP BEGIN

    /**
     * 添加资源或地址到赞同夹
     * @method POST /_api/xpmsns/comment/agree/create
     */
    function create($query, $data ) {

        $u = new \Xpmsns\User\Model\User;
        $user = $u->getUserInfo();
        $user_id = $user["user_id"];

        if ( empty($user_id) ) {
            throw new Excp("用户尚未登录", 402, ["query"=>$query, "data"=>$data]);
        }

        $time = time();
        $inst = new \Xpmsns\Comment\Model\Agree;
        $data["user_id"] = $user_id;
        try {
            $resp =  $inst->create( $data );
        } catch( Excp $e ) {
            if ( $e->getCode() == 1062 ) {
                throw new Excp("你已经赞同过了", 1062, ["user_id"=>$user_id, "data"=>$data]);
            }
            throw $e;
        }

        try {  // 触发用户赞同行为
            \Xpmsns\User\Model\Behavior::trigger("xpmsns/comment/agree/create", $resp);
        }catch(Excp $e) { $e->log(); }

        return $resp;

    }


    /**
     * 移除赞同记录
     * @method POST /_api/xpmsns/comment/agree/create
     */
    function remove($query, $data ) {


        if (empty($data["agree_id"])){
            throw new Excp("未指定赞同记录", 402, ["query"=>$query, "data"=>$data]);
        }

        $u = new \Xpmsns\User\Model\User;
        $user = $u->getUserInfo();
        $user_id = $user["user_id"];

        if ( empty($user_id) ) {
            throw new Excp("用户尚未登录", 402, ["query"=>$query, "data"=>$data]);
        }

        $time = time();
        $inst = new \Xpmsns\Comment\Model\Agree;
        $agree_id = $data["agree_id"];

        $agree = $fav->getByAgreeId($agree_id);
        if ( $agree["user_id"] != $user_id ) {
            throw new Excp("您没有该赞同的删除权限", 403, ["user_id"=>$user_id, "data"=>$data]);
        }

       $resp = $inst->remove($agree_id, "agree_id");

       if ( $resp == true ) {
           return ["code"=>0, "message"=>"移除赞同成功"];
       }

       throw new Excp("移除赞同失败", 500, ["query"=>$query, "data"=>$data]);

    }


    /**
     * 查询赞同记录
     * @method GET /_api/xpmsns/comment/agree/search
     * @see \Xpmsns\Comment\Model\Agree
     */
    function search( $query, $data ) {
        $u = new \Xpmsns\User\Model\User;
        $user = $u->getUserInfo();
        $user_id = $user["user_id"];

        if ( empty($user_id) ) {
            throw new Excp("用户尚未登录", 402, ["query"=>$query, "data"=>$data]);
        }

        $query["user_user_id"] = $user_id;
        $inst = new \Xpmsns\Comment\Model\Agree;
        $rows = $inst->search( $query );
        $inst->getSource($rows["data"]);
        return $rows;

    }
    // @KEEP END









}