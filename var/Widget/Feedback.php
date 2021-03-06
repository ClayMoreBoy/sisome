<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 反馈提交
 *
 * @category typecho
 * @package Widget
 * @copyright Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license GNU General Public License 2.0
 * @version $Id$
 */

/**
 * 反馈提交组件
 *
 * @category typecho
 * @package Widget
 * @copyright Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license GNU General Public License 2.0
 */
class Widget_Feedback extends Widget_Abstract_Comments implements Widget_Interface_Do
{
    /**
     * 内容对象
     *
     * @access private
     * @var Widget_Archive
     */
    private $_content;

    /**
     * 评论处理函数
     *
     * @throws Typecho_Widget_Exception
     * @throws Exception
     * @throws Typecho_Exception
     */
    private function comment()
    {
        // modified_by_jiangmuzi 2015.09.23
        // 必须登录后才可以回复
        if (!$this->user->hasLogin()) {
            $this->widget('Widget_Notice')->set(_t('请先<a href="%s">登录</a>',$this->options->someUrl('login',null,false).'?redir='.$this->request->getRequestUrl()),NULL,'success');
            $this->response->goBack();
        }
        // end modified
        
        // 使用安全模块保护
        $this->security->protect();

        $comment = array(
            'cid'       =>  $this->_content->cid,
            'created'   =>  $this->options->gmtTime,
            'agent'     =>  $this->request->getAgent(),
            'ip'        =>  $this->request->getIp(),
            'ownerId'   =>  $this->_content->author->uid,
            'type'      =>  'comment',
            'status'    =>  !$this->_content->allow('edit') && $this->options->commentsRequireModeration ? 'waiting' : 'approved'
        );

        /** 判断父节点 */
        if ($parentId = $this->request->filter('int')->get('parent')) {
            if ($this->options->commentsThreaded && ($parent = $this->db->fetchRow($this->db->select('coid', 'cid')->from('table.comments')
            ->where('coid = ?', $parentId))) && $this->_content->cid == $parent['cid']) {
                $comment['parent'] = $parentId;
            } else {
                throw new Typecho_Widget_Exception(_t('父级评论不存在'));
            }
        }

        //检验格式
        $validator = new Typecho_Validate();
        $validator->addRule('author', 'required', _t('必须填写用户名'));
        $validator->addRule('author', 'xssCheck', _t('请不要在用户名中使用特殊字符'));
        $validator->addRule('author', array($this, 'requireUserLogin'), _t('您所使用的用户名已经被注册,请登录后再次提交'));
        $validator->addRule('author', 'maxLength', _t('用户名最多包含200个字符'), 200);

        if ($this->options->commentsRequireMail && !$this->user->hasLogin()) {
            $validator->addRule('mail', 'required', _t('必须填写电子邮箱地址'));
        }

        $validator->addRule('mail', 'email', _t('邮箱地址不合法'));
        $validator->addRule('mail', 'maxLength', _t('电子邮箱最多包含200个字符'), 200);

        if ($this->options->commentsRequireUrl && !$this->user->hasLogin()) {
            $validator->addRule('url', 'required', _t('必须填写个人主页'));
        }
        $validator->addRule('url', 'url', _t('个人主页地址格式错误'));
        $validator->addRule('url', 'maxLength', _t('个人主页地址最多包含200个字符'), 200);

        $validator->addRule('text', 'required', _t('必须填写评论内容'));

        $comment['text'] = $this->request->text;

        /** 登录用户信息 */
        $comment['author'] = $this->user->screenName;
        $comment['mail'] = $this->user->mail;
        $comment['url'] = $this->user->url;

        /** 记录登录用户的id */
        $comment['authorId'] = $this->user->uid;

        if ($error = $validator->run($comment)) {
            /** 记录文字 */
            Typecho_Cookie::set('__typecho_remember_text', $comment['text']);
            throw new Typecho_Widget_Exception(implode("\n", $error));
        }

        /** 生成过滤器 */
        try {
            $comment = $this->pluginHandle()->comment($comment, $this->_content);
        } catch (Typecho_Exception $e) {
            Typecho_Cookie::set('__typecho_remember_text', $comment['text']);
            throw $e;
        }
        
        // modified_by_jiangmuzi 2015.09.23
        // 解析@数据
        $search = $replace  = $atMsg = array();
        $pattern = "/@([^@^\\s^:]{1,})([\\s\\:\\,\\;]{0,1})/";
        preg_match_all ( $pattern, $comment['text'], $matches );
        if(!empty($matches[1])){
            $matches[1] = array_unique($matches[1]);
            foreach($matches[1] as $name){
                if(empty($name)) continue;
                $atUser = $this->widget('Widget_Users_Query@name_'.$name,array('name'=>$name));
                if(!$atUser->have()) continue;
                $search[] = '@'.$name;
                $replace[] = '<a href="'.$atUser->ucenter.'" target="_blank">@'.$name.'</a>';
                
                //提醒at用户
                if($comment['authorId'] != $atUser->uid && $atUser->uid != $comment['ownerId']) {
                    $atMsg[] = array(
                        'uid'=>$atUser->uid,
                        'type'=>'at'
                    );
                }
            }
            if(!empty($search)){
                $comment['text'] = str_replace(@$search, @$replace, $comment['text']);
            }
        }
        // end modified
        /** 添加评论 */
        $commentId = $this->insert($comment);
        Typecho_Cookie::delete('__typecho_remember_text');
        $this->db->fetchRow($this->select()->where('coid = ?', $commentId)
        ->limit(1), array($this, 'push'));
        
        $this->db->query($this->db->update('table.contents')->rows(array('lastUid'=>$this->authorId,'lastComment'=>$this->created))->where('cid = ?',$this->cid));
        //提醒主题作者
        if($comment['authorId'] != $comment['ownerId']){
            $atMsg[] = array(
                'uid'=>$comment['ownerId'],
                'type'=>'comment'
            );
        }
        if(!empty($atMsg)){
            foreach($atMsg as $v){
                $this->widget('Widget_Users_Messages')->addMessage($v['uid'],$commentId,$v['type']);
            }
        }
        
        Widget_Common::credits('reply');
        
        /** 评论完成接口 */
        $this->pluginHandle()->finishComment($this);
        
        $this->response->goBack('#' . $this->theId);
    }

    /**
     * 引用处理函数
     *
     * @access private
     * @return void
     */
    private function trackback()
    {
        /** 如果不是POST方法 */
        if (!$this->request->isPost() || $this->request->getReferer()) {
            $this->response->redirect($this->_content->permalink);
        }

        /** 如果库中已经存在当前ip为spam的trackback则直接拒绝 */
        if ($this->size($this->select()
        ->where('status = ? AND ip = ?', 'spam', $this->request->getIp())) > 0) {
            /** 使用404告诉机器人 */
            throw new Typecho_Widget_Exception(_t('找不到内容'), 404);
        }

        $trackback = array(
            'cid'       =>  $this->_content->cid,
            'created'   =>  $this->options->gmtTime,
            'agent'     =>  $this->request->getAgent(),
            'ip'        =>  $this->request->getIp(),
            'ownerId'   =>  $this->_content->author->uid,
            'type'      =>  'trackback',
            'status'    =>  $this->options->commentsRequireModeration ? 'waiting' : 'approved'
        );

        $trackback['author'] = $this->request->filter('trim')->blog_name;
        $trackback['url'] = $this->request->filter('trim')->url;
        $trackback['text'] = $this->request->excerpt;

        //检验格式
        $validator = new Typecho_Validate();
        $validator->addRule('url', 'required', 'We require all Trackbacks to provide an url.')
        ->addRule('url', 'url', 'Your url is not valid.')
        ->addRule('url', 'maxLength', 'Your url is not valid.', 200)
        ->addRule('text', 'required', 'We require all Trackbacks to provide an excerption.')
        ->addRule('author', 'xssCheck', 'Your blog name is not valid.');
        //->addRule('author', 'required', 'We require all Trackbacks to provide an blog name.')
        //->addRule('author', 'maxLength', 'Your blog name is not valid.', 200);

        $validator->setBreak();
        if ($error = $validator->run($trackback)) {
            $message = array('success' => 1, 'message' => current($error));
            $this->response->throwXml($message);
        }

        /** 截取长度 */
        $trackback['text'] = Typecho_Common::subStr($trackback['text'], 0, 100, '[...]');

        /** 如果库中已经存在重复url则直接拒绝 */
        if ($this->size($this->select()
        ->where('cid = ? AND url = ? AND type <> ?', $this->_content->cid, $trackback['url'], 'comment')) > 0) {
            /** 使用403告诉机器人 */
            throw new Typecho_Widget_Exception(_t('禁止重复提交'), 403);
        }

        /** 生成过滤器 */
        $trackback = $this->pluginHandle()->trackback($trackback, $this->_content);

        /** 添加引用 */
        $trackbackId = $this->insert($trackback);

        /** 评论完成接口 */
        $this->pluginHandle()->finishTrackback($this);

        /** 返回正确 */
        $this->response->throwXml(array('success' => 0, 'message' => 'Trackback has registered.'));
    }

    /**
     * 过滤评论内容
     *
     * @access public
     * @param string $text 评论内容
     * @return string
     */
    public function filterText($text)
    {
        $text = str_replace("\r", '', trim($text));
        $text = preg_replace("/\n{2,}/", "\n\n", $text);

        return Typecho_Common::removeXSS(Typecho_Common::stripTags(
        $text, $this->options->commentsHTMLTagAllowed));
    }

    /**
     * 对已注册用户的保护性检测
     *
     * @access public
     * @param string $userName 用户名
     * @return void
     */
    public function requireUserLogin($userName)
    {
        if ($this->user->hasLogin() && $this->user->screenName != $userName) {
            /** 当前用户名与提交者不匹配 */
            return false;
        } else if (!$this->user->hasLogin() && $this->db->fetchRow($this->db->select('uid')
        ->from('table.users')->where('screenName = ? OR name = ?', $userName, $userName)->limit(1))) {
            /** 此用户名已经被注册 */
            return false;
        }

        return true;
    }

    /**
     * 初始化函数
     *
     * @access public
     * @return void
     * @throws Typecho_Widget_Exception
     */
    public function action()
    {
        /** 回调方法 */
        $callback = $this->request->type;
        $this->_content = Typecho_Router::match($this->request->permalink);

        /** 判断内容是否存在 */
        if (false !== $this->_content && $this->_content instanceof Widget_Archive &&
        $this->_content->have() && $this->_content->is('single') &&
        in_array($callback, array('comment', 'trackback'))) {

            /** 如果文章不允许反馈 */
            if ('comment' == $callback) {
                /** 评论关闭 */
                if (!$this->_content->allow('comment')) {
                    throw new Typecho_Widget_Exception(_t('对不起,此内容的反馈被禁止.'), 403);
                }
                
                /** 检查来源 */
                if ($this->options->commentsCheckReferer && 'false' != $this->parameter->checkReferer) {
                    $referer = $this->request->getReferer();

                    if (empty($referer)) {
                        throw new Typecho_Widget_Exception(_t('评论来源页错误.'), 403);
                    }

                    $refererPart = parse_url($referer);
                    $currentPart = parse_url($this->_content->permalink);

                    if ($refererPart['host'] != $currentPart['host'] ||
                    0 !== strpos($refererPart['path'], $currentPart['path'])) {
                        
                        //自定义首页支持
                        if ('page:' . $this->_content->cid == $this->options->frontPage) {
                            $currentPart = parse_url(rtrim($this->options->siteUrl, '/') . '/');
                            
                            if ($refererPart['host'] != $currentPart['host'] ||
                            0 !== strpos($refererPart['path'], $currentPart['path'])) {
                                throw new Typecho_Widget_Exception(_t('评论来源页错误.'), 403);
                            }
                        } else {
                            throw new Typecho_Widget_Exception(_t('评论来源页错误.'), 403);
                        }
                    }
                }

                /** 检查ip评论间隔 */
                if (!$this->user->pass('editor', true) && $this->_content->authorId != $this->user->uid &&
                $this->options->commentsPostIntervalEnable) {
                    $latestComment = $this->db->fetchRow($this->db->select('created')->from('table.comments')
                    ->where('cid = ?', $this->_content->cid)
                    ->order('created', Typecho_Db::SORT_DESC)
                    ->limit(1));

                    if ($latestComment && ($this->options->gmtTime - $latestComment['created'] > 0 &&
                    $this->options->gmtTime - $latestComment['created'] < $this->options->commentsPostInterval)) {
                        throw new Typecho_Widget_Exception(_t('对不起, 您的发言过于频繁, 请稍侯再次发布.'), 403);
                    }
                }
            }

            /** 如果文章不允许引用 */
            if ('trackback' == $callback && !$this->_content->allow('ping')) {
                throw new Typecho_Widget_Exception(_t('对不起,此内容的引用被禁止.'), 403);
            }

            /** 调用函数 */
            $this->$callback();
        } else {
            throw new Typecho_Widget_Exception(_t('找不到内容'), 404);
        }
    }
}
