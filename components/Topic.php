<?php namespace Eq3w\Forum\Components;

use Auth;
use Flash;
use Event;
use Request;
use Redirect;
use Cms\Classes\Page;
use RainLab\User\Models\User as UserModel;
use RainLab\User\Models\MailBlocker;
use Cms\Classes\ComponentBase;
use ApplicationException;
use Eq3w\Forum\Models\Topic as TopicModel;
use Eq3w\Forum\Models\Channel as ChannelModel;
use Eq3w\Forum\Models\Member as MemberModel;
use Eq3w\Forum\Models\Post as PostModel;
use Eq3w\Forum\Models\TopicFollow;
use Eq3w\Forum\Classes\TopicTracker;
use Exception;

class Topic extends ComponentBase
{
    /**
     * @var boolean Determine if this component is being used by the EmbedChannel component.
     */
    public $embedMode = false;

    /**
     * @var Eq3w\Forum\Models\Topic Topic cache
     */
    protected $topic = null;

    /**
     * @var Eq3w\Forum\Models\Channel Channel cache
     */
    protected $channel = null;

    /**
     * @var Eq3w\Forum\Models\Member Member cache
     */
    protected $member = null;

    /**
     * @var string Reference to the page name for linking to members.
     */
    public $memberPage;

    /**
     * @var string Reference to the page name for linking to channels.
     */
    public $channelPage;

    /**
     * @var string URL to redirect to after posting to the topic.
     */
    public $returnUrl;

    /**
     * @var Collection Posts cache for Twig access.
     */
    public $posts = null;

    public function componentDetails()
    {
        return [
            'name'        => 'eq3w.forum::lang.topicpage.name',
            'description' => 'eq3w.forum::lang.topicpage.self_desc'
        ];
    }

    public function defineProperties()
    {
        return [
            'slug' => [
                'title'       => 'eq3w.forum::lang.topicpage.slug_name',
                'description' => 'eq3w.forum::lang.topicpage.slug_desc',
                'default'     => '{{ :slug }}',
                'type'        => 'string',
            ],
            'postsPerPage' => [
                'title'       => 'eq3w.forum::lang.topicpage.pagination_name',
                'default'     => '20',
                'type'        => 'string',
            ],
            'memberPage' => [
                'title'       => 'eq3w.forum::lang.member.page_name',
                'description' => 'eq3w.forum::lang.member.page_help',
                'type'        => 'dropdown',
                'group'       => 'Links',
            ],
            'channelPage' => [
                'title'       => 'eq3w.forum::lang.topicpage.channel_title',
                'description' => 'eq3w.forum::lang.topicpage.channel_desc',
                'type'        => 'dropdown',
                'group'       => 'Links',
            ],
        ];
    }

    public function getPropertyOptions($property)
    {
        return Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
    }

    public function onRun()
    {
        $this->addCss('assets/css/forum.css');
        $this->addJs('assets/js/forum.js');

        $this->prepareVars();
        $this->page['channel'] = $this->getChannel();
        $this->page['topic']   = $topic = $this->getTopic();
        $this->page['member']  = $member = $this->getMember();
        $this->handleOptOutLinks();

        return $this->preparePostList();
    }

    protected function prepareVars()
    {
        /*
         * Page links
         */
        $this->memberPage   = $this->page['memberPage']  = $this->property('memberPage');
        $this->channelPage  = $this->page['channelPage'] = $this->property('channelPage');
    }

    public function getTopic()
    {
        if ($this->topic !== null) {
            return $this->topic;
        }

        if (!$slug = $this->property('slug')) {
            return null;
        }

        $topic = TopicModel::whereSlug($slug)->first();

        if ($topic) {
            $topic->increaseViewCount();
        }

        return $this->topic = $topic;
    }

    public function getMember()
    {
        if ($this->member !== null) {
            return $this->member;
        }

        return $this->member = MemberModel::getFromUser();
    }

    public function getChannel()
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        if ($topic = $this->getTopic()) {
            $channel = $topic->channel;
        }
        elseif ($channelId = input('channel')) {
            $channel = ChannelModel::find($channelId);
        }
        else {
            $channel = null;
        }

        // Add a "url" helper attribute for linking to the category
        if ($channel) {
            $channel->setUrl($this->channelPage, $this->controller);
        }

        return $this->channel = $channel;
    }

    public function getChannelList()
    {
        return ChannelModel::make()->getRootList('title', 'id');
    }

    protected function preparePostList()
    {
        /*
         * If topic exists, loads the posts
         */
        if ($topic = $this->getTopic()) {
            $currentPage = input('page');
            $searchString = trim(input('search'));
            $posts = PostModel::with('member.user.avatar')->listFrontEnd([
                'page'    => $currentPage,
                'perPage' => $this->property('postsPerPage'),
                'sort'    => 'created_at',
                'topic'   => $topic->id,
                'search'  => $searchString,
            ]);

            /*
             * Add a "url" helper attribute for linking to each member
             */
            $posts->each(function($post){
                if ($post->member)
                    $post->member->setUrl($this->memberPage, $this->controller);
            });

            $this->page['posts'] = $this->posts = $posts;

            /*
             * Pagination
             */
            $queryArr = [];
            if ($searchString) {
                $queryArr['search'] = $searchString;
            }
            $queryArr['page'] = '';
            $paginationUrl = Request::url() . '?' . http_build_query($queryArr);

            $lastPage = $posts->lastPage();
            if ($currentPage == 'last' || $currentPage > $lastPage && $currentPage > 1) {
                return Redirect::to($paginationUrl . $lastPage);
            }

            $this->page['paginationUrl'] = $paginationUrl;
        }

        /*
         * Set topic as watched
         */
        if ($this->topic && $this->member) {
            TopicTracker::instance()->markTopicTracked($this->topic);
        }

        /*
         * Return URL
         */
        if ($this->getChannel()) {
            if ($this->embedMode == 'single') {
                $returnUrl = null;
            }
            elseif ($this->embedMode) {
                $returnUrl = $this->currentPageUrl([$this->paramName('slug') => null]);
            }
            else {
                $returnUrl = $this->channel->url;
            }

             $this->returnUrl = $this->page['returnUrl'] = $returnUrl;
         }
    }

    protected function handleOptOutLinks()
    {
        if (!$topic = $this->getTopic()) return;
        if (!$action = post('action')) return;
        if (!in_array($action, ['unfollow', 'unsubscribe'])) return;

        /*
         * Attempt to find member using dry authentication
         */
        if (!$member = $this->getMember()) {
            if (!($authCode = post('auth')) || !strpos($authCode, '!')) {
                return;
            }
            list($hash, $userId) = explode('!', $authCode);
            if (!$user = UserModel::find($userId)) {
                return;
            }
            if (!$member = MemberModel::getFromUser($user)) {
                return;
            }

            $expectedCode = TopicFollow::makeAuthCode($action, $topic, $member);
            if ($authCode != $expectedCode) {
                Flash::error(\Lang::get('eq3w.forum::fe_lang.topic.invalid_code'));
                return;
            }
        }

        /*
         * Unfollow link
         */
        if ($action == 'unfollow') {
            TopicFollow::unfollow($topic, $member);
            Flash::success(\Lang::get('eq3w.forum::fe_lang.topic.notifications_disabled'));
        }

        /*
         * Unsubscribe link
         */
        if ($action == 'unsubscribe' && $member->user) {
            MailBlocker::addBlock('eq3w.forum::mail.topic_reply', $member->user);
            Flash::success(\Lang::get('eq3w.forum::fe_lang.topic.forum_notifications_disabled'));
        }

    }
    public function onCreate()
    {
        try {
            if (!$user = Auth::getUser()) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.should_logged_in'));
            }

            $member = $this->getMember();
            $channel = $this->getChannel();
            
            if ($channel->is_moderated && !$member->is_moderator) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.no_creation'));
            }

            if (TopicModel::checkThrottle($member)) {

                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.wait'));

            }

            if ($member->is_banned) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.account_banned'));
            }

            $topic = TopicModel::createInChannel($channel, $member, post());
            $topicUrl = $this->currentPageUrl([$this->paramName('slug') => $topic->slug]);

            Flash::success(post('flash', \Lang::get('eq3w.forum::fe_lang.topic.cr_success')));

            /*
             * Extensbility
             */
            Event::fire('eq3w.forum.topic.create', [$this, $topic, $topicUrl]);
            $this->fireEvent('topic.create', [$topic, $topicUrl]);

            /*
             * Redirect to the intended page after successful update
             */
            $redirectUrl = post('redirect', $topicUrl);

            return Redirect::to($redirectUrl);
        }
        catch (Exception $ex) {
            Flash::error($ex->getMessage());
        }
    }

    public function onPost()
    {
        try {
            if (!$user = Auth::getUser()) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.should_logged_in'));
            }

            $member = $this->getMember();
            $topic = $this->getTopic();

            if (!$topic || !$topic->canPost()) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.cant_edit'));
            }

            $post = PostModel::createInTopic($topic, $member, post());
            $postUrl = $this->currentPageUrl([$this->paramName('slug') => $topic->slug]);

            TopicFollow::sendNotifications($topic, $post, $postUrl);
            Flash::success(post('flash', \Lang::get('eq3w.forum::fe_lang.topic.resp_success')));

            /*
             * Extensbility
             */
            Event::fire('eq3w.forum.topic.post', [$this, $post, $postUrl]);
            $this->fireEvent('topic.post', [$post, $postUrl]);

            /*
             * Redirect to the intended page after successful update
             */
            $redirectUrl = post('redirect', $postUrl);

            return Redirect::to($redirectUrl.'?page=last#post-'.$post->id);
        }
        catch (Exception $ex) {
            Flash::error($ex->getMessage());
        }
    }

    public function onUpdate()
    {
        $this->page['member'] = $member = $this->getMember();

        $topic = $this->getTopic();
        $post = PostModel::find(post('post'));

        if (!$post || !$post->canEdit()) {
            throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.permission_denied'));
        }

        /*
         * Supported modes: edit, view, delete, save
         */
        $mode = post('mode', 'edit');
        if ($mode == 'save') {
            if (!$topic || !$topic->canPost()) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.cant_edit'));
            }

            $post->fill(post());
            $post->save();

            // First post will update the topic subject
            if ($topic->first_post->id == $post->id) {
                $topic->fill(['subject' => post('subject')]);
                $topic->save();
            }
        }
        elseif ($mode == 'delete') {
            $post->delete();
        }

        $this->page['mode'] = $mode;
        $this->page['post'] = $post;
        $this->page['topic'] = $topic;
    }

    public function onQuote()
    {
        if (!$user = Auth::getUser()) {
            throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.should_logged_in'));
        }

        if (!$post = PostModel::find(post('post'))) {
            throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.topic.no_post'));
        }

        $result = $post->toArray();
        $result['author'] = $post->member ? $post->member->username : '???';

        return $result;
    }

    public function onMove()
    {
        $member = $this->getMember();
        if (!$member->is_moderator) {
            Flash::error(\Lang::get('eq3w.forum::fe_lang.access_denied'));
            return;
        }

        $channelId = post('channel');
        $channel = ChannelModel::find($channelId);
        if ($channel) {
            $this->getTopic()->moveToChannel($channel);
            Flash::success(post('flash', \Lang::get('eq3w.forum::fe_lang.topic.moved')));
        }
        else {
            Flash::error(\Lang::get('eq3w.forum::fe_lang.topic.unable_to_move'));
        }
    }

    public function onFollow()
    {
        try {
            if (!$user = Auth::getUser()) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.should_logged_in'));
            }

            $this->page['member'] = $member = $this->getMember();
            $this->page['topic'] = $topic = $this->getTopic();

            TopicFollow::toggle($topic, $member);
            $member->touchActivity();
        }
        catch (Exception $ex) {
            if (Request::ajax()) throw $ex; else Flash::error($ex->getMessage());
        }
    }

    public function onSticky()
    {
        try {
            $member = $this->getMember();
            if (!$member || !$member->is_moderator) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.access_denied'));
            }

            if ($topic = $this->getTopic()) {
                $topic->stickyTopic();
            }

            $this->page['member'] = $member;
            $this->page['topic']  = $topic;
        }
        catch (Exception $ex) {
            if (Request::ajax()) throw $ex; else Flash::error($ex->getMessage());
        }
    }

    public function onLock()
    {
        try {
            $member = $this->getMember();
            if (!$member || !$member->is_moderator) {
                throw new ApplicationException(\Lang::get('eq3w.forum::fe_lang.access_denied'));
            }

            if ($topic = $this->getTopic()) {
                $topic->lockTopic();
            }

            $this->page['member'] = $member;
            $this->page['topic']  = $topic;
        }
        catch (Exception $ex) {
            if (Request::ajax()) throw $ex; else Flash::error($ex->getMessage());
        }
    }
}
