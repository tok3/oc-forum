<?php namespace Eq3w\Forum;

use Event;
use Backend;
use RainLab\User\Models\User;
use Eq3w\Forum\Models\Member;
use System\Classes\PluginBase;
use RainLab\User\Controllers\Users as UsersController;

/**
 * Forum Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['RainLab.User'];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'eq3w.forum::lang.plugin.name',
            'description' => 'eq3w.forum::lang.plugin.description',
            'author'      => 'Alexey Bobkov, Samuel Georges',
            'icon'        => 'icon-comments',
            'homepage'    => 'https://github.com/eq3w/forum-plugin'
        ];
    }

    public function boot()
    {
        User::extend(function($model) {
            $model->hasOne['forum_member'] = ['Eq3w\Forum\Models\Member'];

            $model->bindEvent('model.beforeDelete', function() use ($model) {
                $model->forum_member && $model->forum_member->delete();
            });
        });

        UsersController::extendFormFields(function($widget, $model, $context) {
            // Prevent extending of related form instead of the intended User form
            if (!$widget->model instanceof \RainLab\User\Models\User) {
                return;
            }
            if ($context != 'update') {
                return;
            }
            if (!Member::getFromUser($model)) {
                return;
            }

            $widget->addFields([
                'forum_member[username]' => [
                    'label'   => 'eq3w.forum::lang.settings.username',
                    'tab'     => 'Forum',
                    'comment' => 'eq3w.forum::lang.settings.username_comment'
                ],
                'forum_member[is_moderator]' => [
                    'label'   => 'eq3w.forum::lang.settings.moderator',
                    'type'    => 'checkbox',
                    'tab'     => 'Forum',
                    'span'    => 'auto',
                    'comment' => 'eq3w.forum::lang.settings.moderator_comment'
                ],
                'forum_member[is_banned]' => [
                    'label'   => 'eq3w.forum::lang.settings.banned',
                    'type'    => 'checkbox',
                    'tab'     => 'Forum',
                    'span'    => 'auto',
                    'comment' => 'eq3w.forum::lang.settings.banned_comment'
                ]
            ], 'primary');
        });

        UsersController::extendListColumns(function($widget, $model) {
            if (!$model instanceof \RainLab\User\Models\User) {
                return;
            }

            $widget->addColumns([
                'forum_member_username' => [
                    'label'      => 'eq3w.forum::lang.settings.forum_username',
                    'relation'   => 'forum_member',
                    'select'     => 'username',
                    'searchable' => false,
                    'invisible'  => true
                ]
            ]);
        });
    }

    public function registerComponents()
    {
        return [
           '\Eq3w\Forum\Components\Channels'     => 'forumChannels',
           '\Eq3w\Forum\Components\Channel'      => 'forumChannel',
           '\Eq3w\Forum\Components\Topic'        => 'forumTopic',
           '\Eq3w\Forum\Components\Topics'       => 'forumTopics',
           '\Eq3w\Forum\Components\Posts'        => 'forumPosts',
           '\Eq3w\Forum\Components\Member'       => 'forumMember',
           '\Eq3w\Forum\Components\EmbedTopic'   => 'forumEmbedTopic',
           '\Eq3w\Forum\Components\EmbedChannel' => 'forumEmbedChannel'
        ];
    }
    
    public function registerPermissions() 
    {
        return [
            'eq3w.forum::lang.settings.channels' => [
                'tab'   => 'eq3w.forum::lang.settings.channels',
                'label' => 'eq3w.forum::lang.settings.channels_desc'
            ]
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label'       => 'eq3w.forum::lang.settings.channels',
                'description' => 'eq3w.forum::lang.settings.channels_desc',
                'icon'        => 'icon-comments',
                'url'         => Backend::url('eq3w/forum/channels'),
                'category'    => 'eq3w.forum::lang.plugin.name',
                'order'       => 500,
                'permissions' => ['eq3w.forum::lang.settings.channels'],
            ]
        ];
    }

    public function registerMailTemplates()
    {
        return [
            'eq3w.forum::mail.topic_reply'   => 'Notification to followers when a post is made to a topic.',
            'eq3w.forum::mail.member_report' => 'Notification to moderators when a member is reported to be a spammer.'
        ];
    }
}
