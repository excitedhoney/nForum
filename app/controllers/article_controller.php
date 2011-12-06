<?php
/**
 * Article controller for nforum
 *
 * @author xw
 */
App::import("vendor", array("model/section", "model/board", "model/threads", "inc/ubb"));
class ArticleController extends AppController {
    
    private $_threads;
    private $_board;

    public function beforeFilter(){
        parent::beforeFilter();
        if(!isset($this->params['name'])){
            $this->error(ECode::$BOARD_NONE);
        }

        try{
            $boardName = $this->params['name'];
            if(preg_match("/^\d+$/", $boardName))
                throw new BoardNullException();
            $this->_board = Board::getInstance($boardName);
        }catch(BoardNullException $e){
            $this->error(ECode::$BOARD_UNKNOW);
        }

        if(!$this->_board->hasReadPerm(User::getInstance())){
            if(!$this->ByrSession->isLogin)
                $this->requestLogin();
            $this->error(ECode::$BOARD_NOPERM);
        }
        $this->_board->setOnBoard();
        $this->ByrSession->Cookie->write("XWJOKE", "hoho", false);
    }

    public function index(){
        $this->cache(false);
        $this->css[] = "article.css";
        $this->js[] = "forum.share.js";
        $this->js[] = "forum.article.js";
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"阅读文章");

        App::import('Sanitize');
        App::import('vendor', array("inc/pagination", "inc/astro"));

        if(!isset($this->params['gid']) || $this->params['gid'] == '0')
            $this->error(ECode::$ARTICLE_NONE);
        try{
            $gid = $this->params['gid'];
            $this->_threads = Threads::getInstance($gid, $this->_board);
        }catch(ThreadsNullException $e){
            $this->error(ECode::$ARTICLE_NONE);
        }
        $p = isset($this->params['url']['p'])?$this->params['url']['p']:1;
        $pagination = new Pagination($this->_threads, Configure::read("pagination.article"));
        $articles = $pagination->getPage($p);

        $u = User::getInstance();
        $bm = $u->isBM($this->_board) || $u->isAdmin();
        $info = array();
        $curTime = strtotime(date("Y-m-d", time()));
        foreach($articles as $v){
            try{
                $own = User::getInstance($v->OWNER); 
                $astro = Astro::getAstro($own->birthmonth, $own->birthday);

                if($own->getCustom("userdefine0", 29) == 0){
                    $hide = true;
                    $gender = -1;
                }else{
                    $hide = false;
                    $gender = ($own->gender == "77")?0:1;
                }
                $user = array(
                    "id" => $own->userid,
                    "name" => Sanitize::html($own->username),
                    "gender" => $gender,
                    "furl" => Sanitize::html($own->getFace()),
                    "width" => ($own->userface_width === 0)?"":$own->userface_width,
                    "height" => ($own->userface_height === 0)?"":$own->userface_height,
                    "post" => $own->numposts,
                    "astro" => $astro['name'],
                    "online" => $own->isOnline(),
                    "level" => $own->getLevel(),
                    "time" => date(($curTime > $own->lastlogin)?"Y-m-d":"H:i:s", $own->lastlogin),
                    "first" => date("Y-m-d", $own->firstlogin),
                    "hide" => $hide
                );
            }catch(UserNullException $e){
                $user = false;
            }

            $content = $v->getHtml(true);
            //hard to match all the format of ip
            //$pattern = '/<font class="f[0-9]+">※( |&nbsp;)来源:·.+?\[FROM:( |&nbsp;)[0-9a-zA-Z.:*]+\]<\/font><font class="f000">( +<br \/>)+ +<\/font>/';
            //preg_match($pattern, $content, $match);
            //$content = preg_replace($pattern, "", $content);
            if(Configure::read("ubb.parse")){
                //remove ubb of nickname in first and title second line
                preg_match("'^(.*?<br \/>.*?<br \/>)'", $content, $res);
                $content = preg_replace("'(^.*?<br \/>.*?<br \/>)'", '', $content);
                $content = XUBB::remove($res[1]) . $content;
                $content = XUBB::parse($content);
            }
            $info[] = array(
                "id" => $v->ID,
                "owner" => $user,
                "op" => ($v->OWNER == $u->userid || $bm)?1:0,
                "pos" => $v->getPos(),
                "poster" => $v->OWNER,
                "content" => $content,
                "subject" => $v->isSubject()
                //"from" => isset($match[0])?preg_replace("/<br \/>/", "", $match[count($match)-1]):""
            );
        }
        $this->title = Sanitize::html($this->_threads->TITLE);
        $link = "{$this->base}/article/{$this->_board->NAME}/{$gid}?p=%page%";
        $pageBar = $pagination->getPageBar($p, $link);
        $this->set("bName", $this->_board->NAME);
        $this->set("gid", $gid);
        $this->set("anony", $this->_board->isAnony());
        $this->set("tmpl", $this->_board->isTmplPost());
        $this->set("info", $info);
        $this->set("pageBar", $pageBar);
        $this->set("title", $this->title);
        $this->set("totalNum", $this->_threads->getTotalNum());
        $this->set("curPage", $pagination->getCurPage());
        $this->set("totalPage", $pagination->getTotalPage());
        //for the quick reply, raw encode the space
        $this->set("reid", $this->_threads->ID);
        if(!strncmp($this->_threads->TITLE, "Re: ", 4))
            $reTitle = $this->_threads->TITLE;
        else
            $reTitle = "Re: " . $this->_threads->TITLE;

        //hack for post with ajax,need utf-8 encoding
        $reTitle = iconv($this->encoding, 'UTF-8//TRANSLIT', $reTitle);
        $this->set("reTitle", rawurlencode($reTitle));
        //for default search day 
        $this->set("searchDay", Configure::read("search.day"));
        $this->set("searchDay", Configure::read("search.day"));
        $this->jsr[] = "window.user_post=" . ($this->_board->hasPostPerm($u) && !$this->_board->isDeny($u)?"true":"false") . ";";
    }

    public function post(){
        $article = $this->_postInit();

        $this->js[] = "forum.upload.js";
        $this->js[] = "forum.post.js";
        $this->css[] = "post.css";
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"发表文章");

        $reTitle = $reContent = "";
        if(false !== $article){
            $reContent = "\n".$article->getRef();
            //remove ref ubb tag
            $reContent = XUBB::remove($reContent);
            if(!strncmp($article->TITLE, "Re: ", 4))
                $reTitle = $article->TITLE;
            else
                $reTitle = "Re: " . $article->TITLE;
        }else{
            if($this->_board->isTmplPost()){
                $this->redirect("/article/" . $this->_board->NAME . "/tmpl");
            }
        }
        $u = User::getInstance();
        $sigOption = array();
        foreach(range(0, $u->signum) as $v){
            if($v == 0)
                $sigOption["$v"] = "不使用签名档";
            else
                $sigOption["$v"] = "使用第{$v}个";
        }
        App::import('Sanitize');
        $reTitle = Sanitize::html($reTitle);
        $reContent = Sanitize::html($reContent);
        $sigOption["-1"] = "使用随机签名档";
        $this->set("bName", $this->_board->NAME);
        $this->set("anony", $this->_board->isAnony());
        $this->set("outgo", $this->_board->isOutgo());
        $this->set("isAtt", $this->_board->isAttach());
        $this->set("reTitle", $reTitle);
        $this->set("reContent", $reContent);
        $this->set("sigOption", $sigOption);
        $this->set("sigNow", $u->signature);

        $upload = Configure::read("article");
        $this->set("maxNum", $upload['att_num']);
        $this->set("maxSize", $upload['att_size']);
    }

    public function ajax_post(){
        if(!$this->RequestHandler->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $article = $this->_postInit();
        if(false === $article && $this->_board->isTmplPost())
            $this->error();

        if(!isset($this->params['form']['subject']))
            $this->error(ECode::$POST_NOSUB);
        if(!isset($this->params['form']['content']))
            $this->error(ECode::$POST_NOCON);
        $subject = rawurldecode(trim($this->params['form']['subject']));
        $subject = iconv('UTF-8', 'GBK//TRANSLIT', $subject);
        if(strlen($subject) > 60)
            $subject = nforum_fix_gbk(substr($subject,0,60));
        $content = $this->params['form']['content'];
        $content = iconv('UTF-8', 'GBK//TRANSLIT', $content);
        $sig = User::getInstance()->signature;
        $email = 0;$anony = null;$outgo = 0;
        if(isset($this->params['form']['signature']))
            $sig = intval($this->params['form']['signature']);
        if(isset($this->params['form']['email']))
            $email = 1;
        if(isset($this->params['form']['anony']) && $this->_board->isAnony())
            $anony = 1;
        if(isset($this->params['form']['outgo']) && $this->_board->isOutgo())
            $outgo = 1;
        try{
            if(false === $article)
                $id = Article::post($this->_board, $subject, $content, $sig, $email, $anony, $outgo);
            else
                $id = $article->reply($subject, $content, $sig, $email, $anony, $outgo);
            $gid = Article::getInstance($id, $this->_board);
            $gid = $gid->GROUPID;
        }catch(ArticlePostException $e){
            $this->error($e->getMessage());
        }catch(ArticleNullException $e){
            $this->error(ECode::$ARTICLE_NONE);
        }

        $ret['ajax_code'] = ECode::$POST_OK;
        $ret['default'] = '/board/' .  $this->_board->NAME;
        $ret['list'][] = array("text" => '版面:' . $this->_board->DESC, "url" => "/board/" . $this->_board->NAME);
        $ret['list'][] = array("text" => '主题:' . str_replace('Re: ', '', $subject), "url" => '/article/' .  $this->_board->NAME . '/' . $gid);
        $ret['list'][] = array("text" => Configure::read("site.name"), "url" => Configure::read("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function ajax_delete(){
        if(!$this->RequestHandler->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $u = User::getInstance();
        if(isset($this->params['id'])){
            try{
                $a = Article::getInstance(intval($this->params['id']), $this->_board);
                if(!$a->hasEditPerm($u))
                    $this->error(ECode::$ARTICLE_NODEL);
                if(!$a->delete())
                    $this->error(ECode::$ARTICLE_NODEL);
            }catch(ArticleNullException $e){
                $this->error(ECode::$ARTICLE_NONE);
            }
        }
        $ret['ajax_code'] = ECode::$ARTICLE_DELOK;
        $ret['default'] = '/board/' .  $this->_board->NAME;
        $ret['list'][] = array("text" => '版面:' . $this->_board->DESC, "url" => "/board/" . $this->_board->NAME);
        $ret['list'][] = array("text" => Configure::read("site.name"), "url" => Configure::read("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function edit(){
        $this->_editInit();
        $id = $this->params['id'];

        $this->js[] = "forum.upload.js";
        $this->js[] = "forum.post.js";
        $this->css[] = "post.css";
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"编辑文章");

        $article = Article::getInstance($id, $this->_board);
        App::import('Sanitize');
        $title = Sanitize::html($article->TITLE);
        $content = Sanitize::html($article->getContent());
        $this->set("bName", $this->_board->NAME);
        $this->set("isAtt", $this->_board->isAttach());
        $this->set("title", $title);
        $this->set("content", $content);
        $this->set("eid", $id);

        $upload = Configure::read("article");
        $this->set("maxNum", $upload['att_num']);
        $this->set("maxSize", $upload['att_size']);
    }

    public function ajax_edit(){
        if(!$this->RequestHandler->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->_editInit();
        $id = $this->params['id'];
        if(!isset($this->params['form']['subject']))
            $this->error(ECode::$POST_NOSUB);
        if(!isset($this->params['form']['content']))
            $this->error(ECode::$POST_NOCON);
        $subject = trim($this->params['form']['subject']);
        $subject = iconv('UTF-8', 'GBK//TRANSLIT', $subject);
        if(strlen($subject) > 60)
            $subject = nforum_fix_gbk(substr($subject,0,60));
        $content = trim($this->params['form']['content']);
        $content = iconv('UTF-8', 'GBK//TRANSLIT', $content);
        $article = Article::getInstance($id, $this->_board);
        if(!$article->update($subject, $content))
            $this->error(ECode::$ARTICLE_EDITERROR);

        $ret['ajax_code'] = ECode::$ARTICLE_EDITOK;
        $ret['default'] = '/board/' .  $this->_board->NAME;
        $ret['list'][] = array("text" => '版面:' . $this->_board->DESC, "url" => "/board/" . $this->_board->NAME);
        $ret['list'][] = array("text" => '主题:' . str_replace('Re: ', '', $subject), "url" => '/article/' .  $this->_board->NAME . '/' . $article->GROUPID);
        $ret['list'][] = array("text" => Configure::read("site.name"), "url" => Configure::read("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function ajax_preview(){
        App::import('Sanitize');
        if(!isset($this->params['form']['subject']) || !isset($this->params['form']['content'])){
            $this->error();
        }

        $subject = rawurldecode(trim($this->params['form']['subject']));
        $subject = iconv('UTF-8', 'GBK//TRANSLIT', $subject);
        if(strlen($subject) > 60)
            $subject = nforum_fix_gbk(substr($subject,0,60));
        $subject = Sanitize::html($subject);

        $content = $this->params['form']['content'];
        $content = iconv('UTF-8', 'GBK//TRANSLIT', $content);
        $content = preg_replace("/\n/", "<br />", Sanitize::html($content));
        if(Configure::read("ubb.parse"))
            $content = XUBB::parse($content);
        $this->set('no_html_data', array("subject"=>$subject,"content"=>$content));
    }

    public function ajax_forward(){
        if(!$this->RequestHandler->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->requestLogin();
        if(!isset($this->params['id']))
            $this->error(ECode::$ARTICLE_NONE);
        if(!isset($this->params['form']['target']))
            $this->error(ECode::$USER_NONE);
        $id = intval($this->params['id']);
        $target = trim($this->params['form']['target']);
        $threads = isset($this->params['form']['threads']);
        $noref = isset($this->params['form']['noref']);
        $noatt = isset($this->params['form']['noatt']);
        $noansi = isset($this->params['form']['noansi']);
        $big5 = isset($this->params['form']['big5']);
        try{
            $article = Article::getInstance($id, $this->_board);
            if($threads){
                $t = Threads::getInstance($article->GROUPID, $this->_board);
                $t->forward($target, $t->ID, $noref, $noatt, $noansi, $big5);
            }else{
                $article->forward($target, $noatt, $noansi, $big5);
            }
        }catch(ArticleNullException $e){
            $this->error(ECode::$ARTICLE_NONE);
        }catch(ArticleForwardException $e){
            $this->error($e->getMessage());
        }
         
        $ret['ajax_code'] = ECode::$ARTICLE_FORWARDOK;
        $this->set('no_html_data', $ret);
    }

    public function tmpl(){
        $article = $this->_postInit();
        App::import("vendor", "model/template");
        App::import('Sanitize');
        $this->js[] = "forum.tmpl.js";
        $this->css[] = "post.css";
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"模版发文");

        if(isset($this->params['url']['tmplid'])){
            //template question
            $id = trim($this->params['url']['tmplid']);
            try{
                $t = Template::getInstance($id, $this->_board);
            }catch(TemplateNullException $e){
                $this->error(ECode::$TMPL_ERROR);
            }
            $info = array();
            try{
                foreach(range(0, $t->CONT_NUM - 1) as $i){
                    $q = $t->getQ($i);
                    $info[$i] = array("text" => Sanitize::html($q['TEXT']), "len"=>$q['LENGTH']);
                }
            }catch(TemplateQNullException $e){
                $this->error();
            }
            $this->set("tmplId", $id);
            $this->set("bName", $this->_board->NAME);
            $this->set("info", $info);
            $this->set("num", $t->NUM);
            $this->set("tmplTitle", Sanitize::html($t->TITLE));
            $this->set("title", $t->TITLE_TMPL);
            $this->render("tmpl_que");
        }else{
            //template list
            try{
                $page = new Pagination(Template::getTemplates($this->_board));
            }catch(TemplateNullException $e){
                $this->error(ECode::$TMPL_ERROR);
            }
            $info = $page->getPage(1);

            foreach($info as &$v){
                $v = array("name" => Sanitize::html($v->TITLE), "num" => $v->CONT_NUM);
            }
            $this->set("info", $info);
            $this->set("bName", $this->_board->NAME);
        }
    }

    public function ajax_tmpl(){
        $article = $this->_postInit();
        App::import("vendor", "model/template");
        if(!$this->RequestHandler->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        if(!isset($this->params['form']['tmplid']))
            $this->error(ECode::$TMPL_ERROR);
        $id = trim($this->params['form']['tmplid']);
        try{
            $t = Template::getInstance($id, $this->_board);
        }catch(TemplateNullException $e){
            $this->error(ECode::$TMPL_ERROR);
        }

        $val = $this->params['form']['q'];
        foreach($val as &$v)
            $v = iconv('UTF-8', 'GBK//TRANSLIT', $v);
        $pre = $t->getPreview($val);
        $subject = $pre[0];
        $preview = $pre[1];
        $content = $pre[2];
        if($this->params['form']['pre'] == "0"){
            $u = User::getInstance();
            try{
                if(false === $article)
                    $id = Article::post($this->_board, $subject, $content, $u->signature);
                else
                    $id = $article->reply($subject, $content, $u->signature);
                $gid = Article::getInstance($id, $this->_board);
                $gid = $gid->GROUPID;
            }catch(ArticlePostException $e){
                $this->error($e->getMessage());
            }

            $ret['ajax_code'] = ECode::$POST_OK;
            $ret['default'] = "/board/" . $this->_board->NAME;
            $ret['list'][] = array("text" => '版面:' . $this->_board->DESC, "url" => "/board/" . $this->_board->NAME);
            $ret['list'][] = array("text" => '主题:' . str_replace('Re: ', '', $subject), "url" => '/article/' .  $this->_board->NAME . '/' . $gid);
            $ret['list'][] = array("text" => Configure::read("site.name"), "url" => Configure::read("site.home"));
            $this->set('no_html_data', $ret);
        }else{
            App::import('Sanitize');
            $subject = Sanitize::html($subject);
            if(Configure::read("ubb.parse"))
                $content = XUBB::parse($content);
            $this->set('no_html_data', array("subject"=>$subject,"content"=>$preview, "reid"=>(false === $article)?0:$article->ID));
        }
    }

    //if there is reid,return reArticle,otherwise return false
    private function _postInit(){
        if($this->_board->isReadOnly()){
            $this->error(ECode::$BOARD_READONLY);
        }
        if(!$this->_board->hasPostPerm(User::getInstance())){
            $this->error(ECode::$BOARD_NOPOST);
        }
        if($this->_board->isDeny(User::getInstance())){
            $this->error(ECode::$POST_BAN);
        }
        if(isset($this->params['id']))
            $reID = intval($this->params['id']);
        else if(isset($this->params['form']['id']))
            $reID = intval($this->params['form']['id']);
        else if(isset($this->params['url']['id']))
            $reID = intval($this->params['url']['id']);
        else
            $reID = 0;
        if(empty($reID))
            return false;
        else
            $this->set('reid', $reID);
        if($this->_board->isNoReply())
            $this->error(ECode::$BOARD_NOREPLY);
        try{
            $reArticle = Article::getInstance($reID, $this->_board);
        }catch(ArticleNullException $e){
            $this->error(ECode::$ARTICLE_NOREID);
        }
        if($reArticle->isNoRe())
            $this->error(ECode::$ARTICLE_NOREPLY);
        return $reArticle;
    }

    //return the edit article
    private function _editInit(){
        if($this->_board->isReadOnly()){
            $this->error(ECode::$BOARD_READONLY);
        }
        if(!$this->_board->hasPostPerm(User::getInstance())){
            $this->error(ECode::$BOARD_NOPOST);
        }
        if(!isset($this->params['id']))
            $this->error(ECode::$ARTICLE_NONE);
        $id = intval($this->params['id']);
        try{
            $article = Article::getInstance($id, $this->_board);
        }catch(ArticleNullException $e){
            $this->error(ECode::$ARTICLE_NONE);
        }
        $u = User::getInstance();
        if(!$article->hasEditPerm($u))
            $this->error(ECode::$ARTICLE_NOEDIT);
        $this->set('reid', $id);
        return $article;
    }

    private function _getNotice(){
        $root = Configure::read("section.{$this->_board->SECNUM}");
        $this->notice[] = array("url"=>"/section/{$this->_board->SECNUM}", "text"=>$root[0]);
        $boards = array(); $tmp = $this->_board;
        while(!is_null($tmp = $tmp->getDir())){
            $boards[] = array("url"=>"/section/{$tmp->NAME}", "text"=>$tmp->DESC);
        }
        foreach($boards as $v)
            $this->notice[] = $v;
        $this->notice[] = array("url"=>"/board/{$this->_board->NAME}", "text"=>$this->_board->DESC);
    }
}
?>
