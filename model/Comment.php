<?php
/**
 * Class Comment 
 * 评论数据模型
 *
 * 程序作者: XpmSE机器人
 * 最后修改: 2019-04-14 17:27:38
 * 程序母版: /data/stor/private/templates/xpmsns/model/code/model/Name.php
 */
namespace Xpmsns\Comment\Model;
               
use \Xpmse\Excp;
use \Xpmse\Model;
use \Xpmse\Utils;
use \Xpmse\Conf;
use \Mina\Cache\Redis as Cache;
use \Xpmse\Loader\App as App;
use \Xpmse\Job;
use \Xpmse\Content;


class Comment extends Model {




    /**
     * 数据缓存对象
     */
    protected $cache = null;

	/**
	 * 评论数据模型【3】
	 * @param array $param 配置参数
	 *              $param['prefix']  数据表前缀，默认为 xpmsns_comment_
	 */
	function __construct( $param=[] ) {

		parent::__construct(array_merge(['prefix'=>'xpmsns_comment_'],$param));
        $this->table('comment'); // 数据表名称 xpmsns_comment_comment
         // + Redis缓存
        $this->cache = new Cache([
            "prefix" => "xpmsns_comment_comment:",
            "host" => Conf::G("mem/redis/host"),
            "port" => Conf::G("mem/redis/port"),
            "passwd"=> Conf::G("mem/redis/password")
        ]);


       
	}

	/**
	 * 自定义函数 
	 */

    // @KEEP BEGIN

    /**
     * 评论初始化( 注册行为/注册任务/设置默认值等... )
     */
    public function __defaults() {

        // 注册配置项
        $options = [
            [
                "name"=>"评论配置", 
				"key"=>"comment", 
				"default"=>[
                    "status" => "pending",
                    "display" => ["enabled"],
                    "limits" => [ 
                        "interval" => 60,
                        "hour" => 30,
                    ],
				],
				"order"=> 90
            ]
        ];

        $opt = new \Xpmse\Option('xpmsns/comment');
        foreach( $options as $o ) {
            try {
                $opt->register(
                    $o["name"], 
                    $o["key"], 
                    $o["default"], 
                    $o["order"]
                );
            } catch( Excp $e ){  $e->log(); }
        }
    }


    /**
     * 读取评论配置
     */
    public function getOption() {

        $opt = new \Xpmse\Option('xpmsns/comment');
        $option = $opt->get("comment");
        if ( $option === false || !is_array($option)) {
            $option = [
                "status" => "pending",
                "display" => ["enabled"],
                "limits" =>  [ 
                    "interval" => 60,
                    "hour" => 30,
                ],
            ];
        }
        return $option;
    }


    /**
     * 校验发表配额
     * @param string $user_id 用户ID
     * @param array $limits 配置设置
     */
    public function checkLimit( $user_id, $limits = [] ) {

        if ( empty($limits) ) {
            $limits =  [ 
                "interval" => 60,
                "hour" => 30,
            ];
        }
        // 检查发送间隔
        $rows = $this->query()
                    ->where("user_id", "=", $user_id )
                    ->orderBy("created_at", "desc")
                    ->limit(1)
                    ->select(["created_at"])
                    ->get()
                    ->toArray()
                ;
        if ( empty($rows) ) {
            return true;
        }


        $row = current( $rows );
        $time = strtotime( $row["created_at"]);
        $now = time();
        if ( intval($now - $time) < $limits["interval"] ) {
            throw new Excp("操作过快，两次发表间隔时长{$limits["interval"]}秒", 400, [
                "fields" => ["interval"],
                "messages" => ["interval"=>"操作过快，两次发表间隔时长{$limits["interval"]}秒"]            
            ]);
        }

        // 检查小时限额
        $cnt = $this->query()
                    ->where("user_id", "=", $user_id )
                    ->orderBy("created_at", "desc")
                    ->where("created_at", '>', date('Y-m-d H:i:s', $now-3600) )
                    ->count(["comment_id"])
                ;
        if ( $cnt >= $limits["hour"] ) {
            throw new Excp("每小时最多发布{$limits["hour"]}条评论", 400, [
                "fields" => ["hour"],
                "messages" => ["hour"=>"每小时最多发布{$limits["hour"]}条评论"]
            ]);
        }
    
        return true;
    }


    /**
     * 读取回复数据
     * @param array $rows 评论数据引用
     */
    public function withReplies( & $rows ) {

        $comment_ids = array_column( $rows, "comment_id");
        if ( empty($comment_ids) ) {
            return;
        }

        $qb = Utils::getTab("xpmsns_comment_comment as comment", "{none}")->query();
        

        $qb->leftJoin("xpmsns_user_user as user", "user.user_id", "=", "comment.user_id"); // 连接用户
        $select = [
            "comment.comment_id","comment.outer_id","user.mobile","comment.desktop","comment.mobile","comment.wxapp","comment.app","comment.status","comment.created_at","comment.updated_at",
            "comment.user_id","user.name","user.nickname",
            "comment.reply_id",
        ];
        $replies = $qb->whereIn("reply_id", $comment_ids)
                    ->select($select)
                    ->orderBy("created_at", "desc")
                    ->get()
                    ->toArray()
                ;
        $map = [];
        foreach( $replies as $reply ) {
            $reply_id = $reply["reply_id"];
            $map[$reply_id][] = $reply;
        }

        foreach( $rows as & $row ) {
            $row["replies"] = [];
            if ( is_array($map["{$row["comment_id"]}"]) ) {
                $row["replies"] = $map["{$row["comment_id"]}"];
            }
        }

    }

    /**
     * 查询资源评论数量
     */
    public function withCounts( & $rows ) {
        $outer_ids = array_column( $rows, "outer_id");
        if ( empty($outer_ids) ) {
            return;
        }

        $counts = $this->query()
                ->whereIn("outer_id", $outer_ids )
                ->groupBy("outer_id")
                ->select("outer_id")
                ->selectRaw("Count(comment_id) as cnt")
                ->get()
                ->toArray()
            ;
        $map = array_combine(array_column($counts, 'outer_id'), $counts);

        foreach( $rows as & $row ) {
            $row["comment_cnt"] = 0;
            if ( is_array($map["{$row["outer_id"]}"]) ) {
                $row["comment_cnt"] = $map["{$row["outer_id"]}"]["cnt"];
            }
        }
    }

    // @KEEP END


	/**
	 * 创建数据表
	 * @return $this
	 */
	public function __schema() {

		// 评论ID
		$this->putColumn( 'comment_id', $this->type("string", ["length"=>128, "unique"=>true, "null"=>true]));
		// 外部内容ID
		$this->putColumn( 'outer_id', $this->type("string", ["length"=>128, "index"=>true, "null"=>true]));
		// 用户ID
		$this->putColumn( 'user_id', $this->type("string", ["length"=>128, "index"=>true, "null"=>true]));
		// 回复评论ID
		$this->putColumn( 'reply_id', $this->type("string", ["length"=>128, "index"=>true, "null"=>true]));
		// 回复用户ID
		$this->putColumn( 'reply_user_id', $this->type("string", ["length"=>128, "index"=>true, "null"=>true]));
		// 正文
		$this->putColumn( 'content', $this->type("text", ["null"=>true]));
		// 小程序正文
		$this->putColumn( 'wxapp', $this->type("text", ["json"=>true, "null"=>true]));
		// 客户端正文
		$this->putColumn( 'app', $this->type("text", ["json"=>true, "null"=>true]));
		// 浏览器正文
		$this->putColumn( 'desktop', $this->type("text", ["null"=>true]));
		// 手机浏览器正文
		$this->putColumn( 'mobile', $this->type("text", ["null"=>true]));
		// 状态
		$this->putColumn( 'status', $this->type("string", ["length"=>32, "index"=>true, "default"=>"enabled", "null"=>true]));

		return $this;
	}


	/**
	 * 处理读取记录数据，用于输出呈现
	 * @param  array $rs 待处理记录
	 * @return
	 */
	public function format( & $rs ) {
     
		$fileFields = []; 

        // 处理图片和文件字段 
        $this->__fileFields( $rs, $fileFields );

		// 格式化: 状态
		// 返回值: "_status_types" 所有状态表述, "_status_name" 状态名称,  "_status" 当前状态表述, "status" 当前状态数值
		if ( array_key_exists('status', $rs ) && !empty($rs['status']) ) {
			$rs["_status_types"] = [
		  		"pending" => [
		  			"value" => "pending",
		  			"name" => "审核中",
		  			"style" => "warning"
		  		],
		  		"enabled" => [
		  			"value" => "enabled",
		  			"name" => "正常",
		  			"style" => "success"
		  		],
		  		"disabled" => [
		  			"value" => "disabled",
		  			"name" => "关闭",
		  			"style" => "danger"
		  		],
			];
			$rs["_status_name"] = "status";
			$rs["_status"] = $rs["_status_types"][$rs["status"]];
		}

 
		// <在这里添加更多数据格式化逻辑>
		
		return $rs;
	}

	
	/**
	 * 按评论ID查询一条评论记录
	 * @param string $comment_id 唯一主键
	 * @return array $rs 结果集 
	 *          	  $rs["comment_id"],  // 评论ID 
	 *          	  $rs["outer_id"],  // 外部内容ID 
	 *          	  $rs["user_id"],  // 用户ID 
	 *                $rs["user_user_id"], // user.user_id
	 *          	  $rs["reply_id"],  // 回复评论ID 
	 *          	  $rs["reply_user_id"],  // 回复用户ID 
	 *                $rs["reply_user_user_id"], // user.user_id
	 *          	  $rs["content"],  // 正文 
	 *          	  $rs["wxapp"],  // 小程序正文 
	 *          	  $rs["app"],  // 客户端正文 
	 *          	  $rs["desktop"],  // 浏览器正文 
	 *          	  $rs["mobile"],  // 手机浏览器正文 
	 *          	  $rs["status"],  // 状态 
	 *          	  $rs["created_at"],  // 创建时间 
	 *          	  $rs["updated_at"],  // 更新时间 
	 *                $rs["user_created_at"], // user.created_at
	 *                $rs["user_updated_at"], // user.updated_at
	 *                $rs["user_group_id"], // user.group_id
	 *                $rs["user_name"], // user.name
	 *                $rs["user_idno"], // user.idno
	 *                $rs["user_idtype"], // user.idtype
	 *                $rs["user_iddoc"], // user.iddoc
	 *                $rs["user_nickname"], // user.nickname
	 *                $rs["user_sex"], // user.sex
	 *                $rs["user_city"], // user.city
	 *                $rs["user_province"], // user.province
	 *                $rs["user_country"], // user.country
	 *                $rs["user_headimgurl"], // user.headimgurl
	 *                $rs["user_language"], // user.language
	 *                $rs["user_birthday"], // user.birthday
	 *                $rs["user_bio"], // user.bio
	 *                $rs["user_bgimgurl"], // user.bgimgurl
	 *                $rs["user_mobile"], // user.mobile
	 *                $rs["user_mobile_nation"], // user.mobile_nation
	 *                $rs["user_mobile_full"], // user.mobile_full
	 *                $rs["user_email"], // user.email
	 *                $rs["user_contact_name"], // user.contact_name
	 *                $rs["user_contact_tel"], // user.contact_tel
	 *                $rs["user_title"], // user.title
	 *                $rs["user_company"], // user.company
	 *                $rs["user_zip"], // user.zip
	 *                $rs["user_address"], // user.address
	 *                $rs["user_remark"], // user.remark
	 *                $rs["user_tag"], // user.tag
	 *                $rs["user_user_verified"], // user.user_verified
	 *                $rs["user_name_verified"], // user.name_verified
	 *                $rs["user_verify"], // user.verify
	 *                $rs["user_verify_data"], // user.verify_data
	 *                $rs["user_mobile_verified"], // user.mobile_verified
	 *                $rs["user_email_verified"], // user.email_verified
	 *                $rs["user_extra"], // user.extra
	 *                $rs["user_password"], // user.password
	 *                $rs["user_pay_password"], // user.pay_password
	 *                $rs["user_status"], // user.status
	 *                $rs["user_inviter"], // user.inviter
	 *                $rs["user_follower_cnt"], // user.follower_cnt
	 *                $rs["user_following_cnt"], // user.following_cnt
	 *                $rs["user_name_message"], // user.name_message
	 *                $rs["user_verify_message"], // user.verify_message
	 *                $rs["user_client_token"], // user.client_token
	 *                $rs["user_user_name"], // user.user_name
	 *                $rs["user_article_cnt"], // user.article_cnt
	 *                $rs["user_question_cnt"], // user.question_cnt
	 *                $rs["user_answer_cnt"], // user.answer_cnt
	 *                $rs["user_favorite_cnt"], // user.favorite_cnt
	 *                $rs["user_coin"], // user.coin
	 *                $rs["user_balance"], // user.balance
	 *                $rs["user_priority"], // user.priority
	 *                $rs["user_team_name"], // user.team_name
	 *                $rs["user_school"], // user.school
	 *                $rs["user_grade"], // user.grade
	 *                $rs["reply_user_created_at"], // user.created_at
	 *                $rs["reply_user_updated_at"], // user.updated_at
	 *                $rs["reply_user_group_id"], // user.group_id
	 *                $rs["reply_user_name"], // user.name
	 *                $rs["reply_user_idno"], // user.idno
	 *                $rs["reply_user_idtype"], // user.idtype
	 *                $rs["reply_user_iddoc"], // user.iddoc
	 *                $rs["reply_user_nickname"], // user.nickname
	 *                $rs["reply_user_sex"], // user.sex
	 *                $rs["reply_user_city"], // user.city
	 *                $rs["reply_user_province"], // user.province
	 *                $rs["reply_user_country"], // user.country
	 *                $rs["reply_user_headimgurl"], // user.headimgurl
	 *                $rs["reply_user_language"], // user.language
	 *                $rs["reply_user_birthday"], // user.birthday
	 *                $rs["reply_user_bio"], // user.bio
	 *                $rs["reply_user_bgimgurl"], // user.bgimgurl
	 *                $rs["reply_user_mobile"], // user.mobile
	 *                $rs["reply_user_mobile_nation"], // user.mobile_nation
	 *                $rs["reply_user_mobile_full"], // user.mobile_full
	 *                $rs["reply_user_email"], // user.email
	 *                $rs["reply_user_contact_name"], // user.contact_name
	 *                $rs["reply_user_contact_tel"], // user.contact_tel
	 *                $rs["reply_user_title"], // user.title
	 *                $rs["reply_user_company"], // user.company
	 *                $rs["reply_user_zip"], // user.zip
	 *                $rs["reply_user_address"], // user.address
	 *                $rs["reply_user_remark"], // user.remark
	 *                $rs["reply_user_tag"], // user.tag
	 *                $rs["reply_user_user_verified"], // user.user_verified
	 *                $rs["reply_user_name_verified"], // user.name_verified
	 *                $rs["reply_user_verify"], // user.verify
	 *                $rs["reply_user_verify_data"], // user.verify_data
	 *                $rs["reply_user_mobile_verified"], // user.mobile_verified
	 *                $rs["reply_user_email_verified"], // user.email_verified
	 *                $rs["reply_user_extra"], // user.extra
	 *                $rs["reply_user_password"], // user.password
	 *                $rs["reply_user_pay_password"], // user.pay_password
	 *                $rs["reply_user_status"], // user.status
	 *                $rs["reply_user_inviter"], // user.inviter
	 *                $rs["reply_user_follower_cnt"], // user.follower_cnt
	 *                $rs["reply_user_following_cnt"], // user.following_cnt
	 *                $rs["reply_user_name_message"], // user.name_message
	 *                $rs["reply_user_verify_message"], // user.verify_message
	 *                $rs["reply_user_client_token"], // user.client_token
	 *                $rs["reply_user_user_name"], // user.user_name
	 *                $rs["reply_user_article_cnt"], // user.article_cnt
	 *                $rs["reply_user_question_cnt"], // user.question_cnt
	 *                $rs["reply_user_answer_cnt"], // user.answer_cnt
	 *                $rs["reply_user_favorite_cnt"], // user.favorite_cnt
	 *                $rs["reply_user_coin"], // user.coin
	 *                $rs["reply_user_balance"], // user.balance
	 *                $rs["reply_user_priority"], // user.priority
	 *                $rs["reply_user_team_name"], // user.team_name
	 *                $rs["reply_user_school"], // user.school
	 *                $rs["reply_user_grade"], // user.grade
	 */
	public function getByCommentId( $comment_id, $select=['*']) {
		
		if ( is_string($select) ) {
			$select = explode(',', $select);
		}


		// 增加表单查询索引字段
		array_push($select, "comment.comment_id");
		$inwhereSelect = $this->formatSelect( $select ); // 过滤 inWhere 查询字段

		// 创建查询构造器
		$qb = Utils::getTab("xpmsns_comment_comment as comment", "{none}")->query();
 		$qb->leftJoin("xpmsns_user_user as user", "user.user_id", "=", "comment.user_id"); // 连接用户
 		$qb->leftJoin("xpmsns_user_user as reply_user", "reply_user.user_id", "=", "comment.reply_user_id"); // 连接回复用户
		$qb->where('comment.comment_id', '=', $comment_id );
		$qb->limit( 1 );
		$qb->select($select);
		$rows = $qb->get()->toArray();
		if( empty($rows) ) {
			return [];
		}

		$rs = current( $rows );
		$this->format($rs);

  
  
		return $rs;
	}

		

	/**
	 * 按评论ID查询一组评论记录
	 * @param array   $comment_ids 唯一主键数组 ["$comment_id1","$comment_id2" ...]
	 * @param array   $order        排序方式 ["field"=>"asc", "field2"=>"desc"...]
	 * @param array   $select       选取字段，默认选取所有
	 * @return array 评论记录MAP {"comment_id1":{"key":"value",...}...}
	 */
	public function getInByCommentId($comment_ids, $select=["comment.comment_id","comment.user_id","user.name","user.nickname","user.mobile","comment.content","comment.status","comment.created_at"], $order=["comment.created_at"=>"asc"] ) {
		
		if ( is_string($select) ) {
			$select = explode(',', $select);
		}

		// 增加表单查询索引字段
		array_push($select, "comment.comment_id");
		$inwhereSelect = $this->formatSelect( $select ); // 过滤 inWhere 查询字段

		// 创建查询构造器
		$qb = Utils::getTab("xpmsns_comment_comment as comment", "{none}")->query();
 		$qb->leftJoin("xpmsns_user_user as user", "user.user_id", "=", "comment.user_id"); // 连接用户
 		$qb->leftJoin("xpmsns_user_user as reply_user", "reply_user.user_id", "=", "comment.reply_user_id"); // 连接回复用户
		$qb->whereIn('comment.comment_id', $comment_ids);
		
		// 排序
		foreach ($order as $field => $order ) {
			$qb->orderBy( $field, $order );
		}
		$qb->select( $select );
		$data = $qb->get()->toArray(); 

		$map = [];

  		foreach ($data as & $rs ) {
			$this->format($rs);
			$map[$rs['comment_id']] = $rs;
			
  		}

  

		return $map;
	}


	/**
	 * 按评论ID保存评论记录。(记录不存在则创建，存在则更新)
	 * @param array $data 记录数组 (key:value 结构)
	 * @param array $select 返回的字段，默认返回全部
	 * @return array 数据记录数组
	 */
	public function saveByCommentId( $data, $select=["*"] ) {

		if ( is_string($select) ) {
			$select = explode(',', $select);
		}

		// 增加表单查询索引字段
		array_push($select, "comment.comment_id");
		$inwhereSelect = $this->formatSelect( $select ); // 过滤 inWhere 查询字段
		$rs = $this->saveBy("comment_id", $data, ["comment_id"], ['_id', 'comment_id']);
		return $this->getByCommentId( $rs['comment_id'], $select );
	}


	/**
	 * 添加评论记录
	 * @param  array $data 记录数组  (key:value 结构)
	 * @return array 数据记录数组 (key:value 结构)
	 */
	function create( $data ) {

		if ( empty($data["comment_id"]) ) { 
			$data["comment_id"] = $this->genId();
        }
        
        // @KEEP BEGIN
        // 解析 Content 
        if ( !empty($data["content"]) ) {
            $content = new Content();
            $content->loadContent( $data["content"] );

            // 解析内容
            $data["desktop"] = $content->html();
            $data["mobile"] = $content->mobile();
            $data["wxapp"] = $content->wxapp();
            $data["app"] = $content->app();
        }
        // @KEEP END

		return parent::create( $data );
	}


	/**
	 * 查询前排评论记录
	 * @param integer $limit 返回记录数，默认100
	 * @param array   $select  选取字段，默认选取所有
	 * @param array   $order   排序方式 ["field"=>"asc", "field2"=>"desc"...]
	 * @return array 评论记录数组 [{"key":"value",...}...]
	 */
	public function top( $limit=100, $select=["comment.comment_id","comment.user_id","user.name","user.nickname","user.mobile","comment.content","comment.status","comment.created_at"], $order=["comment.created_at"=>"asc"] ) {

		if ( is_string($select) ) {
			$select = explode(',', $select);
		}

		// 增加表单查询索引字段
		array_push($select, "comment.comment_id");
		$inwhereSelect = $this->formatSelect( $select ); // 过滤 inWhere 查询字段

		// 创建查询构造器
		$qb = Utils::getTab("xpmsns_comment_comment as comment", "{none}")->query();
 		$qb->leftJoin("xpmsns_user_user as user", "user.user_id", "=", "comment.user_id"); // 连接用户
 		$qb->leftJoin("xpmsns_user_user as reply_user", "reply_user.user_id", "=", "comment.reply_user_id"); // 连接回复用户


		foreach ($order as $field => $order ) {
			$qb->orderBy( $field, $order );
		}
		$qb->limit($limit);
		$qb->select( $select );
		$data = $qb->get()->toArray();


  		foreach ($data as & $rs ) {
			$this->format($rs);
			
  		}

  
		return $data;
	
	}


	/**
	 * 按条件检索评论记录
	 * @param  array  $query
	 *         	      $query['select'] 选取字段，默认选择 ["comment.comment_id","comment.user_id","user.name","user.nickname","user.mobile","comment.content","comment.status","comment.created_at"]
	 *         	      $query['page'] 页码，默认为 1
	 *         	      $query['perpage'] 每页显示记录数，默认为 20
	 *			      $query["keywords"] 按关键词查询
	 *			      $query["comment_id"] 按评论ID查询 ( = )
	 *			      $query["outer_id"] 按外部内容ID查询 ( = )
	 *			      $query["status"] 按状态查询 ( = )
	 *			      $query["user_id"] 按用户ID查询 ( = )
	 *			      $query["reply_id"] 按回复评论ID查询 ( = )
	 *			      $query["reply_user_id"] 按回复用户ID查询 ( = )
	 *			      $query["created_at"] 按查询 ( > )
	 *			      $query["updated_at"] 按查询 ( > )
	 *			      $query["orderby_created_at_asc"]  按name=created_at ASC 排序
	 *			      $query["orderby_updated_at_asc"]  按name=updated_at ASC 排序
	 *			      $query["orderby_created_at_desc"]  按name=created_at DESC 排序
	 *			      $query["orderby_updated_at_desc"]  按name=updated_at DESC 排序
	 *           
	 * @return array 评论记录集 {"total":100, "page":1, "perpage":20, data:[{"key":"val"}...], "from":1, "to":1, "prev":false, "next":1, "curr":10, "last":20}
	 *               	["comment_id"],  // 评论ID 
	 *               	["outer_id"],  // 外部内容ID 
	 *               	["user_id"],  // 用户ID 
	 *               	["user_user_id"], // user.user_id
	 *               	["reply_id"],  // 回复评论ID 
	 *               	["reply_user_id"],  // 回复用户ID 
	 *               	["reply_user_user_id"], // user.user_id
	 *               	["content"],  // 正文 
	 *               	["wxapp"],  // 小程序正文 
	 *               	["app"],  // 客户端正文 
	 *               	["desktop"],  // 浏览器正文 
	 *               	["mobile"],  // 手机浏览器正文 
	 *               	["status"],  // 状态 
	 *               	["created_at"],  // 创建时间 
	 *               	["updated_at"],  // 更新时间 
	 *               	["user_created_at"], // user.created_at
	 *               	["user_updated_at"], // user.updated_at
	 *               	["user_group_id"], // user.group_id
	 *               	["user_name"], // user.name
	 *               	["user_idno"], // user.idno
	 *               	["user_idtype"], // user.idtype
	 *               	["user_iddoc"], // user.iddoc
	 *               	["user_nickname"], // user.nickname
	 *               	["user_sex"], // user.sex
	 *               	["user_city"], // user.city
	 *               	["user_province"], // user.province
	 *               	["user_country"], // user.country
	 *               	["user_headimgurl"], // user.headimgurl
	 *               	["user_language"], // user.language
	 *               	["user_birthday"], // user.birthday
	 *               	["user_bio"], // user.bio
	 *               	["user_bgimgurl"], // user.bgimgurl
	 *               	["user_mobile"], // user.mobile
	 *               	["user_mobile_nation"], // user.mobile_nation
	 *               	["user_mobile_full"], // user.mobile_full
	 *               	["user_email"], // user.email
	 *               	["user_contact_name"], // user.contact_name
	 *               	["user_contact_tel"], // user.contact_tel
	 *               	["user_title"], // user.title
	 *               	["user_company"], // user.company
	 *               	["user_zip"], // user.zip
	 *               	["user_address"], // user.address
	 *               	["user_remark"], // user.remark
	 *               	["user_tag"], // user.tag
	 *               	["user_user_verified"], // user.user_verified
	 *               	["user_name_verified"], // user.name_verified
	 *               	["user_verify"], // user.verify
	 *               	["user_verify_data"], // user.verify_data
	 *               	["user_mobile_verified"], // user.mobile_verified
	 *               	["user_email_verified"], // user.email_verified
	 *               	["user_extra"], // user.extra
	 *               	["user_password"], // user.password
	 *               	["user_pay_password"], // user.pay_password
	 *               	["user_status"], // user.status
	 *               	["user_inviter"], // user.inviter
	 *               	["user_follower_cnt"], // user.follower_cnt
	 *               	["user_following_cnt"], // user.following_cnt
	 *               	["user_name_message"], // user.name_message
	 *               	["user_verify_message"], // user.verify_message
	 *               	["user_client_token"], // user.client_token
	 *               	["user_user_name"], // user.user_name
	 *               	["user_article_cnt"], // user.article_cnt
	 *               	["user_question_cnt"], // user.question_cnt
	 *               	["user_answer_cnt"], // user.answer_cnt
	 *               	["user_favorite_cnt"], // user.favorite_cnt
	 *               	["user_coin"], // user.coin
	 *               	["user_balance"], // user.balance
	 *               	["user_priority"], // user.priority
	 *               	["user_team_name"], // user.team_name
	 *               	["user_school"], // user.school
	 *               	["user_grade"], // user.grade
	 *               	["reply_user_created_at"], // user.created_at
	 *               	["reply_user_updated_at"], // user.updated_at
	 *               	["reply_user_group_id"], // user.group_id
	 *               	["reply_user_name"], // user.name
	 *               	["reply_user_idno"], // user.idno
	 *               	["reply_user_idtype"], // user.idtype
	 *               	["reply_user_iddoc"], // user.iddoc
	 *               	["reply_user_nickname"], // user.nickname
	 *               	["reply_user_sex"], // user.sex
	 *               	["reply_user_city"], // user.city
	 *               	["reply_user_province"], // user.province
	 *               	["reply_user_country"], // user.country
	 *               	["reply_user_headimgurl"], // user.headimgurl
	 *               	["reply_user_language"], // user.language
	 *               	["reply_user_birthday"], // user.birthday
	 *               	["reply_user_bio"], // user.bio
	 *               	["reply_user_bgimgurl"], // user.bgimgurl
	 *               	["reply_user_mobile"], // user.mobile
	 *               	["reply_user_mobile_nation"], // user.mobile_nation
	 *               	["reply_user_mobile_full"], // user.mobile_full
	 *               	["reply_user_email"], // user.email
	 *               	["reply_user_contact_name"], // user.contact_name
	 *               	["reply_user_contact_tel"], // user.contact_tel
	 *               	["reply_user_title"], // user.title
	 *               	["reply_user_company"], // user.company
	 *               	["reply_user_zip"], // user.zip
	 *               	["reply_user_address"], // user.address
	 *               	["reply_user_remark"], // user.remark
	 *               	["reply_user_tag"], // user.tag
	 *               	["reply_user_user_verified"], // user.user_verified
	 *               	["reply_user_name_verified"], // user.name_verified
	 *               	["reply_user_verify"], // user.verify
	 *               	["reply_user_verify_data"], // user.verify_data
	 *               	["reply_user_mobile_verified"], // user.mobile_verified
	 *               	["reply_user_email_verified"], // user.email_verified
	 *               	["reply_user_extra"], // user.extra
	 *               	["reply_user_password"], // user.password
	 *               	["reply_user_pay_password"], // user.pay_password
	 *               	["reply_user_status"], // user.status
	 *               	["reply_user_inviter"], // user.inviter
	 *               	["reply_user_follower_cnt"], // user.follower_cnt
	 *               	["reply_user_following_cnt"], // user.following_cnt
	 *               	["reply_user_name_message"], // user.name_message
	 *               	["reply_user_verify_message"], // user.verify_message
	 *               	["reply_user_client_token"], // user.client_token
	 *               	["reply_user_user_name"], // user.user_name
	 *               	["reply_user_article_cnt"], // user.article_cnt
	 *               	["reply_user_question_cnt"], // user.question_cnt
	 *               	["reply_user_answer_cnt"], // user.answer_cnt
	 *               	["reply_user_favorite_cnt"], // user.favorite_cnt
	 *               	["reply_user_coin"], // user.coin
	 *               	["reply_user_balance"], // user.balance
	 *               	["reply_user_priority"], // user.priority
	 *               	["reply_user_team_name"], // user.team_name
	 *               	["reply_user_school"], // user.school
	 *               	["reply_user_grade"], // user.grade
	 */
	public function search( $query = [] ) {

		$select = empty($query['select']) ? ["comment.comment_id","comment.user_id","user.name","user.nickname","user.mobile","comment.content","comment.status","comment.created_at"] : $query['select'];
		if ( is_string($select) ) {
			$select = explode(',', $select);
		}

		// 增加表单查询索引字段
		array_push($select, "comment.comment_id");
		$inwhereSelect = $this->formatSelect( $select ); // 过滤 inWhere 查询字段

		// 创建查询构造器
		$qb = Utils::getTab("xpmsns_comment_comment as comment", "{none}")->query();
 		$qb->leftJoin("xpmsns_user_user as user", "user.user_id", "=", "comment.user_id"); // 连接用户
 		$qb->leftJoin("xpmsns_user_user as reply_user", "reply_user.user_id", "=", "comment.reply_user_id"); // 连接回复用户

		// 按关键词查找
		if ( array_key_exists("keywords", $query) && !empty($query["keywords"]) ) {
			$qb->where(function ( $qb ) use($query) {
				$qb->where("comment.comment_id", "like", "%{$query['keywords']}%");
				$qb->orWhere("comment.outer_id","like", "%{$query['keywords']}%");
				$qb->orWhere("comment.user_id","like", "%{$query['keywords']}%");
				$qb->orWhere("comment.reply_id","like", "%{$query['keywords']}%");
				$qb->orWhere("comment.reply_user_id","like", "%{$query['keywords']}%");
				$qb->orWhere("user.mobile_full","like", "%{$query['keywords']}%");
			});
		}


		// 按评论ID查询 (=)  
		if ( array_key_exists("comment_id", $query) &&!empty($query['comment_id']) ) {
			$qb->where("comment.comment_id", '=', "{$query['comment_id']}" );
		}
		  
		// 按外部内容ID查询 (=)  
		if ( array_key_exists("outer_id", $query) &&!empty($query['outer_id']) ) {
			$qb->where("comment.outer_id", '=', "{$query['outer_id']}" );
		}
		  
		// 按状态查询 (=)  
		if ( array_key_exists("status", $query) &&!empty($query['status']) ) {
			$qb->where("comment.status", '=', "{$query['status']}" );
		}
		  
		// 按用户ID查询 (=)  
		if ( array_key_exists("user_id", $query) &&!empty($query['user_id']) ) {
			$qb->where("comment.user_id", '=', "{$query['user_id']}" );
		}
		  
		// 按回复评论ID查询 (=)  
		if ( array_key_exists("reply_id", $query) &&!empty($query['reply_id']) ) {
			$qb->where("comment.reply_id", '=', "{$query['reply_id']}" );
		}
		  
		// 按回复用户ID查询 (=)  
		if ( array_key_exists("reply_user_id", $query) &&!empty($query['reply_user_id']) ) {
			$qb->where("comment.reply_user_id", '=', "{$query['reply_user_id']}" );
		}
		  
		// 按查询 (>)  
		if ( array_key_exists("created_at", $query) &&!empty($query['created_at']) ) {
			$qb->where("comment.created_at", '>', "{$query['created_at']}" );
		}
		  
		// 按查询 (>)  
		if ( array_key_exists("updated_at", $query) &&!empty($query['updated_at']) ) {
			$qb->where("comment.updated_at", '>', "{$query['updated_at']}" );
		}
		  

		// 按name=created_at ASC 排序
		if ( array_key_exists("orderby_created_at_asc", $query) &&!empty($query['orderby_created_at_asc']) ) {
			$qb->orderBy("comment.created_at", "asc");
		}

		// 按name=updated_at ASC 排序
		if ( array_key_exists("orderby_updated_at_asc", $query) &&!empty($query['orderby_updated_at_asc']) ) {
			$qb->orderBy("comment.updated_at", "asc");
		}

		// 按name=created_at DESC 排序
		if ( array_key_exists("orderby_created_at_desc", $query) &&!empty($query['orderby_created_at_desc']) ) {
			$qb->orderBy("comment.created_at", "desc");
		}

		// 按name=updated_at DESC 排序
		if ( array_key_exists("orderby_updated_at_desc", $query) &&!empty($query['orderby_updated_at_desc']) ) {
			$qb->orderBy("comment.updated_at", "desc");
		}


		// 页码
		$page = array_key_exists('page', $query) ?  intval( $query['page']) : 1;
		$perpage = array_key_exists('perpage', $query) ?  intval( $query['perpage']) : 20;

		// 读取数据并分页
		$comments = $qb->select( $select )->pgArray($perpage, ['comment._id'], 'page', $page);

  		foreach ($comments['data'] as & $rs ) {
			$this->format($rs);
			
  		}

  	
		// for Debug
		if ($_GET['debug'] == 1) { 
			$comments['_sql'] = $qb->getSql();
			$comments['query'] = $query;
		}

		return $comments;
	}

	/**
	 * 格式化读取字段
	 * @param  array $select 选中字段
	 * @return array $inWhere 读取字段
	 */
	public function formatSelect( & $select ) {
		// 过滤 inWhere 查询字段
		$inwhereSelect = []; $linkSelect = [];
		foreach ($select as $idx=>$fd ) {
			
			// 添加本表前缀
			if ( !strpos( $fd, ".")  ) {
				$select[$idx] = "comment." .$select[$idx];
				continue;
			}
			
			//  连接用户 (user as user )
			if ( trim($fd) == "user.*" || trim($fd) == "user.*"  || trim($fd) == "*" ) {
				$fields = [];
				if ( method_exists("\\Xpmsns\\User\\Model\\User", 'getFields') ) {
					$fields = \Xpmsns\User\Model\User::getFields();
				}

				if ( !empty($fields) ) { 
					foreach ($fields as $field ) {
						$field = "user.{$field} as user_{$field}";
						array_push($linkSelect, $field);
					}

					if ( trim($fd) === "*" ) {
						array_push($linkSelect, "comment.*");
					}
					unset($select[$idx]);	
				}
			}

			else if ( strpos( $fd, "user." ) === 0 ) {
				$as = str_replace('user.', 'user_', $select[$idx]);
				$select[$idx] = $select[$idx] . " as {$as} ";
			}

			else if ( strpos( $fd, "user.") === 0 ) {
				$as = str_replace('user.', 'user_', $select[$idx]);
				$select[$idx] = $select[$idx] . " as {$as} ";
			}

			
			//  连接回复用户 (user as reply_user )
			if ( trim($fd) == "user.*" || trim($fd) == "reply_user.*"  || trim($fd) == "*" ) {
				$fields = [];
				if ( method_exists("\\Xpmsns\\User\\Model\\User", 'getFields') ) {
					$fields = \Xpmsns\User\Model\User::getFields();
				}

				if ( !empty($fields) ) { 
					foreach ($fields as $field ) {
						$field = "reply_user.{$field} as reply_user_{$field}";
						array_push($linkSelect, $field);
					}

					if ( trim($fd) === "*" ) {
						array_push($linkSelect, "comment.*");
					}
					unset($select[$idx]);	
				}
			}

			else if ( strpos( $fd, "user." ) === 0 ) {
				$as = str_replace('user.', 'reply_user_', $select[$idx]);
				$select[$idx] = $select[$idx] . " as {$as} ";
			}

			else if ( strpos( $fd, "reply_user.") === 0 ) {
				$as = str_replace('reply_user.', 'reply_user_', $select[$idx]);
				$select[$idx] = $select[$idx] . " as {$as} ";
			}

		}

		// filter 查询字段
		foreach ($inwhereSelect as & $iws ) {
			if ( is_array($iws) ) {
				$iws = array_unique(array_filter($iws));
			}
		}

		$select = array_unique(array_merge($linkSelect, $select));
		return $inwhereSelect;
	}

	/**
	 * 返回所有字段
	 * @return array 字段清单
	 */
	public static function getFields() {
		return [
			"comment_id",  // 评论ID
			"outer_id",  // 外部内容ID
			"user_id",  // 用户ID
			"reply_id",  // 回复评论ID
			"reply_user_id",  // 回复用户ID
			"content",  // 正文
			"wxapp",  // 小程序正文
			"app",  // 客户端正文
			"desktop",  // 浏览器正文
			"mobile",  // 手机浏览器正文
			"status",  // 状态
			"created_at",  // 创建时间
			"updated_at",  // 更新时间
		];
	}

}

?>