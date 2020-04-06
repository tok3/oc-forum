<?php namespace Eq3w\Forum\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class DropWatchesTables extends Migration
{
    public function up()
    {
        Schema::dropIfExists('eq3w_forum_topic_watches');
        Schema::dropIfExists('eq3w_forum_channel_watches');
    }

    public function down()
    {
        // ...
    }
}
