<?php
/**
 * Class Comment 
 * 评论数据接口 
 *
 * 程序作者: XpmSE机器人
 * 最后修改: 2019-04-14 17:27:36
 * 程序母版: /data/stor/private/templates/xpmsns/model/code/api/Name.php
 */
namespace Xpmsns\Comment\Api;
               

use \Xpmse\Loader\App;
use \Xpmse\Excp;
use \Xpmse\Utils;
use \Xpmse\Api;

class Comment extends Api {

	/**
	 * 评论数据接口
	 */
	function __construct() {
		parent::__construct();
	}

	/**
	 * 自定义函数 
	 */

    // @KEEP BEGIN

    /**
     * 读取当前用户登录信息
     */
    private function getUser() {
        $user = \Xpmsns\User\Model\User::Info();
        $user_id = $user["user_id"];
        if ( empty($user_id) ) {
            throw new Excp("用户尚未登录", 403, ["fields"=>"user_id", "messages"=>["user_id"=>"用户尚未登录"]]);
        }
        return $user;
    }

    /**
     * 读取当前评论配置
     */
    private function getOption() {
        return ( new \Xpmsns\Comment\Model\Comment() )->getOption();
    }



    /**
     * 创建评论
     */
    protected function create( $params, $payload ) {

        // 检查 outer_id
        if ( empty($payload["outer_id"]) ) {
            throw new Excp("未提供外部资源ID", 400, [
                "fields" => ["outer_id"],
                "messages" => ["outer_id"=>"未提供外部资源ID"],
                "payload"=>$payload, "params"=>$params
            ]);
        }

        // 检查评论内容 content
        if ( empty($payload["content"]) || mb_strlen($payload["content"]) < 4 ) {
            throw new Excp("请提供4个字以上的评论内容", 400, [
                "fields" => ["content"],
                "messages" => ["content"=>"请提供4个字以上的评论内容"],
                "payload"=>$payload, "params"=>$params
            ]);
        }

        $user = $this->getUser();
        $user_id = $user["user_id"];
        $option = $this->getOption();

        $comment = new \Xpmsns\Comment\Model\Comment();

        // 校验配额
        $comment->checkLimit( $user_id, $option["limits"] );
        
        // 数据入库
        $data = [
            "user_id" => $user_id,
            "status" => $option["status"],
            "reply_id" =>$payload["reply_id"],
            "reply_user_id" =>$payload["reply_user_id"],
            "content" => $payload["content"],
            "outer_id" => $payload["outer_id"],
        ];
        return $comment->create( $data );
    }

    /**
     * 查询评论
     */
    protected function query( $params, $payload  ) {
        
        $user = $this->getUser();
        $user_id = $user["user_id"];

        $params["reply_id"] = null;

        $comment = new \Xpmsns\Comment\Model\Comment();

        if( empty($params["select"]) ) {
            $params["select"] = [
                "comment.comment_id","comment.outer_id","user.mobile","comment.desktop","comment.mobile","comment.wxapp","comment.app","comment.status","comment.created_at","comment.updated_at",
                "comment.user_id","user.name","user.nickname",
                "comment.reply_id",
            ];
        }

        if ( empty($params["order"]) ) {
            $params["orderby_created_at_desc"] = 1;
        }

        $result = $comment->search( $params );
        $comment->withReplies( $result["data"] );
        return $result;
    }

    // @KEEP END

	/**
	 * 文件上传接口 (上传控件名称 )
	 * @param  array $query [description]
	 *               $query["private"]  上传文件为私有文件
	 * @param  [type] $data  [description]
	 * @return array 文件信息 {"url":"访问地址...", "path":"文件路径...", "origin":"原始文件访问地址..." }
	 */
	protected function upload( $query, $data, $files ) {

		$fname = $files['file']['tmp_name'];
		if ( $query['private'] ) {
			$media = new \Xpmse\Media(["host" => Utils::getHome(), 'private'=>true]);
		} else {
			$media = new \Xpmse\Media(["host" => Utils::getHome()]);
		}
		$ext = $media->getExt($fname);
		$rs = $media->uploadFile($fname, $ext);
		return $rs;
	}

}