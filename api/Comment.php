<?php
/**
 * Class Comment 
 * 评论数据接口 
 *
 * 程序作者: XpmSE机器人
 * 最后修改: 2019-04-13 17:17:55
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