<?php namespace Eq3w\Forum\Updates;

use October\Rain\Database\Updates\Migration;
use DbDongle;

class UpdateTimestampsNullable extends Migration
{
    public function up()
    {
        DbDongle::disableStrictMode();

        DbDongle::convertTimestamps('eq3w_forum_channels');
        DbDongle::convertTimestamps('eq3w_forum_members');
        DbDongle::convertTimestamps('eq3w_forum_posts');
        DbDongle::convertTimestamps('eq3w_forum_topic_followers');
        DbDongle::convertTimestamps('eq3w_forum_topics');
    }

    public function down()
    {
        // ...
    }
}
