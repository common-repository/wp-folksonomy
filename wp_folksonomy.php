<?php
/*
Plugin Name: WP_Folksonomy
Version: 0.8
Plugin URI: http://scott.sherrillmix.com/blog/programmer/web/WP_Folksonomy/
Description: This plugin allows your readers to add tags to your posts (like Flickr or del.icio.us).
Author: Scott Sherrill-Mix
Author URI: http://scott.sherrillmix.com/blog/
*/

//Definitions
global $wpdb;
define('WP_FOLKSONOMY_TABLE',$wpdb->prefix . "wp_folksonomy");
define('WP_FOLKSONOMY_VERSION','0.8');
define('WP_FOLKSONOMY_TAGS_PER_PAGE',10);
define('WP_FOLKSONOMY_RSS_LIMIT',10);
define('WP_FOLKSONOMY_FILE',get_option('siteurl').'/wp-admin/options-general.php?page='.str_replace('\\','/',preg_replace('@.*[\\\\/]wp-content[\\\\/]plugins[\\\\/](.*)@','\1',__FILE__)));
//Hooks
add_action('admin_menu', 'wp_folksonomy_menu');
//add_action('activity_box_end', 'wp_folksonomy_mini');
add_action('pre_get_posts','wp_folksonomy_add_tag');
register_activation_hook(__FILE__,'wp_folksonomy_install');
//add_filter('the_tags', 'wp_folksonomy_tag_adder'); //invalidates html

function wp_folksonomy_menu() {
	if (function_exists('add_options_page')) {
		add_options_page('Folksonomy Control Panel', 'Folksonomy', 1, basename(__FILE__), 'wp_folksonomy_subpanel');
	}
}

function wp_folksonomy_add_tag(){
	if($comment_post_ID = (int) $_POST['wp_folk_comment_post_ID']){
		global $wp_folksonomy_msg;
		global $wpdb;
		$options=wp_folksonomy_get_options();
		//Just take one tag at a time
		$tag_name=explode(',',$_POST['wp_folksonomy_newtag']);
		$tag_name=trim(strip_tags($tag_name[0]));

		$msg=wp_folksonomy_tagcheck($comment_post_ID,$tag_name);

		if(!$msg){
			if($options['approve']=='wait'){
				$status='WAIT';	
				wp_insert_term($tag_name, 'post_tag');
				$msg='wait';
			}else{
				$status='NEW';
				wp_add_post_tags($comment_post_ID,$tag_name);
				$msg='thanks';
			}
			$tagid=is_term($tag_name,'post_tag');
			if($user=wp_get_current_user()){
				$username=$user->data->user_nicename;
			}
			$sql="INSERT INTO " . WP_FOLKSONOMY_TABLE .
							" (object_id,term_id,term_taxonomy_id, tag_datetime, tagger_ip, username, status) " .
							"VALUES ('". $comment_post_ID ."','". $tagid['term_id'] ."','". $tagid['term_taxonomy_id'] ."','". date("Y-m-d H:i:s") ."','". preg_replace('/[^0-9., ]/', '',$_SERVER['REMOTE_ADDR'])."','$username','$status')";
			$results = $wpdb->query($sql);
			if($results&&function_exists('wp_cache_post_change'))wp_cache_post_change($comment_post_ID);
		}
		$wp_folksonomy_msg=$msg;
	}
}

function wp_folksonomy_tagcheck($postID,$tag_name){
	global $wpdb;
	$options=wp_folksonomy_get_options();
	$status = $wpdb->get_row("SELECT post_status, comment_status FROM $wpdb->posts WHERE ID = '$postID'");
	$msg="";

	//CHECK TOO FREQUENT
	$sql="SELECT COUNT(term_id) FROM ".WP_FOLKSONOMY_TABLE.' WHERE tagger_ip = "'.$_SERVER['REMOTE_ADDR'].'" AND TIME_TO_SEC(TIMEDIFF(now(),tag_datetime)) < '.$options['timeLimit']; 
	$result = $wpdb->get_var($sql);
	if($result>=$options['tagsPerTime']) return "toomany";

	//CHECK POST
	if (empty($status->comment_status)) return "nopost";
	elseif ('closed' ==  $status->comment_status) return "nocomments";
	elseif (in_array($status->post_status, array('draft', 'pending')))  return "draft";

	//CHECK USER
	if ($options['user']=='register'){
		$user = wp_get_current_user();	
		// If the user is logged in
		if(!$user->ID) return 'nouser';
	}

	//CHECK TAG
	if ( '' == $tag_name ) return "notag";
	elseif (strlen($tag_name)>25) return "toolong";
	//CHECK IF TAG ALREADY EXISTS
	if($tagID=(is_term($tag_name,'post_tag'))){
		$tagID=$tagID['term_id'];
		$sql="SELECT term_id FROM ".WP_FOLKSONOMY_TABLE." WHERE object_id=$postID AND term_id=$tagID"; 
		$result = $wpdb->get_var($sql);
		if($result) return "duplicate";
	}
	return "";
}

function wp_folksonomy_tag_adder($output=''){
	$options=wp_folksonomy_get_options();
	if ($options['user']=='register') $user = wp_get_current_user();	
	if($options['user']=='all'||$user->ID){
		global $post;
		global $wp_folksonomy_msg;
		if($wp_folksonomy_msg){
			switch($wp_folksonomy_msg){
			case "thanks": $message="Thanks for adding a tag.";break;
			case "toomany": $message="There's been a lot of tags added from your IP address. Thanks but could you wait til a little later? (Have to watch out for stupid bots)";break;
			case "nouser": $message="Sorry you have to be logged in to add a tag.";break;
			case "notag": $message="Please specify a tag.";break;
			case "draft": $message="That post is a draft.";break;
			case "nocomments": $message="Sorry adding tags not allowed on this post.";break;
			case "nopost": $message="Couldn't find post.";break;
			case "duplicate": $message="Tag already added but thanks.";break;
			case "toolong": $message="Tag too long. Sorry.";break;
			case "wait": $message="Thanks. Tag awaiting approval.";break;
			}
		}
		$output.='<form action="'.get_permalink($post->ID).'" method="post" id="tagadder">';
		if($message) $output.="<p>$message</p>";
		$output.='<p><input type="text" name="wp_folksonomy_newtag" id="wp_folksonomy_newtag" value="" size="22" />
		<input name="submit" type="submit" id="tagsubmit" value="Submit Tag" />
		<input type="hidden" name="wp_folk_comment_post_ID" value="'.$post->ID.'" /></p>
		</form>';
	}
	return $output;
}

function wp_folksonomy_add_form(){
	echo wp_folksonomy_tag_adder();
}

function wp_folksonomy_install () {
	global $wpdb;
	if($wpdb->get_var("SHOW TABLES LIKE '".WP_FOLKSONOMY_TABLE."'") != WP_FOLKSONOMY_TABLE) {
		//Codex says these need to be on separate lines
		$sql = "CREATE TABLE " . WP_FOLKSONOMY_TABLE . " (
			term_id bigint(20) NOT NULL,
			term_taxonomy_id bigint(20) NOT NULL,
			object_id bigint(20) NOT NULL,
			status VARCHAR(20) DEFAULT 'NEW',
			tagger_ip varchar(100),
			tag_datetime datetime,
			username varchar(50)
		);";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
}


function wp_folksonomy_display($limit='LIMIT 0,5',$where='',$paginate=FALSE){
	global $wpdb;
	$options=wp_folksonomy_get_options();
	$tags = $wpdb->get_results("SELECT object_id,term_id,term_taxonomy_id, tag_datetime, tagger_ip, username, status FROM ".WP_FOLKSONOMY_TABLE." $where ORDER BY tag_datetime DESC $limit");
	$numtags = $wpdb->get_var("SELECT COUNT(*) FROM ".WP_FOLKSONOMY_TABLE);
	if ($tags){
		$output='<ul>';
		foreach($tags as $tag){
			if(!$name=$tag->username)$name=$tag->tagger_ip;
			$name="<a href='options-general.php?page=wp_folksonomy.php&user=$name'>$name</a>";
			$term=get_term($tag->term_id,'post_tag');
			$post=get_post($tag->object_id);
			if(current_user_can('edit_posts')){
				$deletelink='<small>(<a href="options-general.php?page=wp_folksonomy.php&action=delete&tag='.$tag->term_id.'&post='.$tag->object_id.'">Delete</a>)</small>';
				if($tag->status=='WAIT') $approvelink='<small>(<a href="options-general.php?page=wp_folksonomy.php&action=approve&tag='.$tag->term_id.'&post='.$tag->object_id.'">Approve</a>)</small>';
				else $approvelink=''; 
			}
			$output.="<li>$name added the tag <a href='".get_tag_link($tag->term_id)."' rel='tag'>$term->name</a> to <a href='".get_permalink($post->ID)."'>$post->post_title</a> $deletelink $approvelink</li>";
		}
		$output.='</ul>';
	}
	if($paginate && $numtags>WP_FOLKSONOMY_TAGS_PER_PAGE){
		$output.='<p>Page: ';
		for($i=1;$i<=ceil($numtags/WP_FOLKSONOMY_TAGS_PER_PAGE);$i++ ){
			$pagelink=$i;
			if($paginate!=$i) $pagelink="<a href='options-general.php?page=wp_folksonomy.php&recentpage=$i'>$pagelink</a> ";
			$output.=$pagelink;
		}
		$output.='</p>';
	}
	return $output;
}

function wp_folksonomy_mini(){
	$output.='<div><h3>Folksonomy <a href="options-general.php?page=wp_folksonomy.php" title="More tags&#8230;">&raquo;</a></h3>';
	$output.=wp_folksonomy_display();
	$output.='</div>';
	echo $output;
}

function wp_folksonomy_get_options(){
	$options=get_option('wp_folksonomy');
	if (!isset($options['version'])){
		//Set Default Values Here
		$default_array=array('user'=>'all','approve'=>'immediate','version'=>WP_FOLKSONOMY_VERSION,'javascript'=>'js','timeLimit'=>600,'tagsPerTime'=>10);
		add_option('wp_folksonomy',$default_array,'Options used by WP_Folksonomy',false);
		$options=$default_array;
	}elseif($options['version']<0.8){
		$options['timeLimit']=600;
		$options['tagsPerTime']=10;
		$options['version']=0.8;
		update_option('wp_folksonomy', $options);
	}
	return($options);
}


//Modified from Alex King's 404 Notifier Plugin
function wp_folksonomy_rss_feed() {
	global $wpdb;
	$tags = $wpdb->get_results("SELECT * FROM ".WP_FOLKSONOMY_TABLE." ORDER BY tag_datetime DESC LIMIT ". WP_FOLKSONOMY_RSS_LIMIT);
	header('Content-type: text/xml; charset=' . get_option('blog_charset'), true);
	echo '<?xml version="1.0" encoding="'.get_option('blog_charset').'"?'.'>';
?>
<rss version="2.0" 
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
>

<channel>
	<title><?php echo __('Tag Report for: ','wp_folksonomy'); bloginfo_rss('name'); ?></title>
	<link><?php bloginfo_rss('url') ?></link>
	<description><?php bloginfo_rss("description") ?></description>
	<pubDate><?php echo mysql2date('D, d M Y H:i:s +0000', get_lastpostmodified('GMT'), false); ?></pubDate>
	<generator>http://wordpress.org/?v=<?php bloginfo_rss('version'); ?></generator>
	<language><?php echo get_option('rss_language'); ?></language>
<?php
		if (count($tags) > 0) {
			foreach ($tags as $tag) {
				$term=get_term($tag->term_id,'post_tag');
				$post=get_post($tag->object_id);
				$deletelink='(<a href="options-general.php?page=wp_folksonomy.php&action=delete&ask=true&tag='.$tag->term_id.'&post='.$tag->object_id.'">'.__('Delete','wp_folksonomy').'</a>)';
				if($tag->status=='WAIT') $approvelink='(<a href="options-general.php?page=wp_folksonomy.php&action=approve&tag='.$tag->term_id.'&post='.$tag->object_id.'">'.__('Approve','wp_folksonomy').'</a>)';
				else $approvelink=''; 
				
				$content = '
					<p>'.__('Tag added: ', 'wp_folksonomy').'<a href="'.get_tag_link($tag->term_id).'" rel="tag">'.$term->name.'</a></p>
					<p>'.__('Post: ', 'wp_folksonomy').' <a href="'.get_permalink($post->ID).'">'.$post->post_title.'</a></p>
					<p>'.$deletelink.' '.$approvelink.'</p>
				';
?>
	<item>
		<title><![CDATA[<?php echo $term->name; ?>]]></title>
		<link><![CDATA[<?php echo get_tag_link($tag->term_id); ?>]]></link>
		<pubDate><?php echo mysql2date('D, d M Y H:i:s +0000', $tag->tag_datetime, false); ?></pubDate>
		<guid isPermaLink="false"><?php print($tag->id); ?></guid>
		<description><![CDATA[<?php echo $content; ?>]]></description>
		<content:encoded><![CDATA[<?php echo $content; ?>]]></content:encoded>
	</item>
<?php $items_count++; if (($items_count == get_option('posts_per_rss')) && !is_date()) { break; } } } ?>
</channel>
</rss>
<?php
		die();
}

function wp_folksonomy_search($q){
	if($s=mysql_escape_string($q)){
		global $wpdb;
		$results=$wpdb->get_col("SELECT name FROM $wpdb->terms  WHERE name LIKE ('%$q%')");
		echo join( $results, "\n" );
	}
	die();
}

function wp_folksonomy_js(){
	?>
	jQuery(document).ready(function(){
		jQuery('#wp_folksonomy_newtag').suggest( "<?php echo WP_FOLKSONOMY_FILE.'&wp_folksonomy_search=true' ?>", { delay: 500, minchars: 2 } );
	});

	<?php
	die;
}

add_action('init', 'wp_folksonomy_controller', 99);
function wp_folksonomy_controller(){
	if($_GET['wp_folksonomy_feed']=='true'){
		wp_folksonomy_rss_feed();
	}elseif($_GET['wp_folksonomy_search']&&$q=$_GET['q']){
		wp_folksonomy_search($q);
	}elseif($_GET['wp_folksonomy_js']){
		wp_folksonomy_js();
	}
}

function wp_folksonomy_delete_tag($object,$tag){
	global $wpdb;
	$object = (int) $object;$tag=(int) $tag;
	if(!$term=get_term($tag,'post_tag')) return false;
	$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id = '$object' AND term_taxonomy_id = '$term->term_taxonomy_id'");
	$wpdb->query("DELETE FROM ".WP_FOLKSONOMY_TABLE." WHERE object_id = '$object' AND term_taxonomy_id ='$term->term_taxonomy_id'");
	//Check if any relationships still exist and if not delete tag
	if(!get_objects_in_term($term->term_id, 'post_tag')){
		wp_delete_term($term->term_id, 'post_tag');
	}
	return true;
}

function wp_folksonomy_subpanel(){
	global $wpdb;
	$options=wp_folksonomy_get_options();
	//User submitted something?
	if (isset($_POST['submit'])) {
		//Not using else on the odd chance some weird input gets sent
		if ($_POST['user'] == 'all') $options['user']='all';
		elseif ($_POST['user'] == 'register') $options['user']='register';
		if ($_POST['approve'] == 'immediate') $options['approve']='immediate';
		elseif ($_POST['approve'] == 'wait') $options['approve']='wait';
		if ($_POST['javascript'] == 'js') $options['javascript']='js';
		elseif ($_POST['javascript'] == 'plain') $options['javascript']='plain';
		if ($_POST['javascript'] == 'js') $options['javascript']='js';
		elseif ($_POST['javascript'] == 'plain') $options['javascript']='plain';
		$timeLimit=intval($_POST['timeLimit']);if ($timeLimit>0) $options['timeLimit']=$timeLimit;
		$tagsPerTime=intval($_POST['tagsPerTime']);if ($tagsPerTime>0) $options['tagsPerTime']=$tagsPerTime;
		update_option('wp_folksonomy', $options);
		echo "<div class='updated'><p>Options Updated</p></div>";
	}
	if(current_user_can('edit_posts')){
		if($_GET['action']=='approve'){
			if($_GET['user']){
				$user=mysql_real_escape_string($_GET['user']);
				$tags = $wpdb->get_results("SELECT object_id, term_id FROM ".WP_FOLKSONOMY_TABLE." WHERE status='WAIT' AND (tagger_ip='$user' OR username='$user')");
				$count=0;
				foreach($tags as $tag){
					$taginfo=get_term($tag->term_id,'post_tag');
					wp_add_post_tags($tag->object_id,$taginfo->name);
					$wpdb->query("UPDATE ".WP_FOLKSONOMY_TABLE." SET status='APPROVED', tag_datetime='".date("Y-m-d H:i:s")."' WHERE object_id=$tag->object_id AND term_id=$tag->term_id");
					$count++;
				}
				echo "<div class='updated'><p>$count Tags by $user Approved</p></div>";
				$_GET['user']="";
			}else{
				$post=(int) $_GET['post'];
				$tag=(int) $_GET['tag'];
				if($taginfo=get_term($tag,'post_tag')){
					wp_add_post_tags($post,$taginfo->name);
					$wpdb->query("UPDATE ".WP_FOLKSONOMY_TABLE." SET status='APPROVED', tag_datetime='".date("Y-m-d H:i:s")."' WHERE object_id=$post AND term_id=$tag");
					echo "<div class='updated'><p>Tag $taginfo->name Approved</p></div>";
				}
			}
		}elseif($_GET['action']=='delete'){
			if($_GET['user']){
				$user=mysql_real_escape_string($_GET['user']);
				if($_GET['confirm']=='yes'){
					$tags = $wpdb->get_results("SELECT object_id,term_id FROM ".WP_FOLKSONOMY_TABLE." WHERE tagger_ip='$user' OR username='$user'");
					$count=0;
					foreach($tags as $tag){
						wp_folksonomy_delete_tag($tag->object_id,$tag->term_id);
						$count++;
					}
					echo "<div class='updated'><p>All $count Tags by $user Deleted</p></div>";
					$_GET['user']="";
				}else{
					echo "<div class='updated'><p>If you really want to delete all tags by <strong>$user</strong> (shown below) <a href='options-general.php?page=wp_folksonomy.php&action=delete&user=$user&confirm=yes'>Click Here</a></p></div>";
				}
			}else{
				$term=get_term(intval($_GET['tag']),'post_tag');
				$post=get_post(intval($_GET['post']));
				if($term&&$post){
					if($_GET['ask']=='true'){
						echo "<div class='updated'><p>Do you really want to delete the tag: <strong>$term->name</strong><br/> on your post: $post->post_title?<br/><br/> <a href='options-general.php?page=wp_folksonomy.php&action=delete&tag=${_GET['tag']}&post=${_GET['post']}' class='button save'>Delete It</a></p></div>";
					}else{
						$post=(int) $_GET['post'];
						$tag=$term->term_id;
						#$tag=(int) $_GET['tag'];
						wp_folksonomy_delete_tag($post,$tag);
						echo "<div class='updated'><p>Tag Deleted</p></div>";
					}
				}else{
					echo "<div class='error'><p>Tag not found</p></div>";
				}
			}
		}
	}
	$tag_count=$wpdb->get_var("SELECT COUNT(*) FROM ".WP_FOLKSONOMY_TABLE);
	?>
	<div class="wrap">
	<div><h3>This is the WP_Folksonomy options page.</h3>
	<p>You currently have <?php echo $tag_count;?> user contributed tags on your website. Click on a user/IP address to see all tags submitted from that user (and if necessary delete all of them).</p>
	</div>
	<?php
		if($page=(int) $_GET['recentpage']){
			echo '<div class="wrap"><h3>Tags Page '.$page.'</h3>'.wp_folksonomy_display("LIMIT ".($page-1)*WP_FOLKSONOMY_TAGS_PER_PAGE.",".WP_FOLKSONOMY_TAGS_PER_PAGE,"WHERE status!='WAIT'",$page).'</div>';
		}elseif($_GET['user']){
			$user=mysql_real_escape_string($_GET['user']);
			if($wpdb->get_var("SELECT COUNT(*) FROM ".WP_FOLKSONOMY_TABLE." WHERE status='WAIT' AND (tagger_ip='$user' OR username='$user')")) $approvelink="<small>(<a href='options-general.php?page=wp_folksonomy.php&action=approve&user=$user'>Approve all</a>)</small>";
			if(current_user_can('edit_posts')) $username=$user." <small>(<a href='options-general.php?page=wp_folksonomy.php&action=delete&user=$user'>Delete All</a>)</small> ".$approvelink;
			echo "<div class='wrap'><h3>Tags by $username</h3>".wp_folksonomy_display('',"WHERE tagger_ip='$user' OR username='$user'",$page).'</div>';
		}else{ 
			echo '<div class="wrap"><h3>Recent Tags</h3>'.wp_folksonomy_display("LIMIT 0,".WP_FOLKSONOMY_TAGS_PER_PAGE,"WHERE status!='WAIT'",1).'</div>';
		}
	?>
	<?php	if($list=wp_folksonomy_display("LIMIT 0,20","WHERE status='WAIT'"))echo "<div class='wrap'><h3>Waiting Approval</h3>$list</div>";?>
	<div class='wrap'>
	<p>Set options here:</p>
	<form method="post" action="options-general.php?page=wp_folksonomy.php">
		<ul style="list-style-type: none">
	<li><strong>Registered Users Only?</strong> Who can add tags: <input type="radio" name="user" value="all" <?php if ($options['user']!='register') echo 'checked="checked"';?>/> Everyone <input type="radio" name="user" value="register" <?php if ($options['user']=='register') echo 'checked="checked"';?>/> Registered Users</li>
	<li><strong>Wait for Approval?</strong> Tags do not display until approved: <input type="radio" name="approve" value="immediate" <?php if ($options['approve']!='wait') echo 'checked="checked"';?>/> Display Immediately <input type="radio" name="approve" value="wait" <?php if ($options['approve']=='wait') echo 'checked="checked"';?>/> Wait For Approval</li>
	<li><strong>Helpful Javascript Autocompletion?</strong> Fancy popup (after typing two letters) to help select tags: <input type="radio" name="javascript" value="js" <?php if ($options['javascript']!='plain') echo 'checked="checked"';?>/> Enable <input type="radio" name="javascript" value="plain" <?php if ($options['javascript']=='plain') echo 'checked="checked"';?>/> Just use plain HTML</li>
	<li><strong>Bot Blocking Time Limit:</strong> Allow a maximum of <input type="input" name="tagsPerTime" style="width:4em;" value="<?php echo $options['tagsPerTime'];?>"/> tags per <input type="input" name="timeLimit" style="width:4em;" value="<?php echo $options['timeLimit'];?>"/> seconds</li>
	<li><input type="submit" name="submit" value="Set Options"/></li>
	</ul>
	</form>
	</div>
	<div class="wrap"><p>You can monitor added tags through this <a href="<?php echo WP_FOLKSONOMY_FILE.'&wp_folksonomy_feed=true';?>">RSS feed</a>.</p></div>
	<div class="wrap"><h3>Instructions</h3><p>Unfortunately there is no automatic hook near the tags in Wordpress so you'll have to make a minor change to one of your theme files. We're aiming to create a tiny form to allow your users to add tags. Many themes will be using the wordpress function <code>&lt;?php the_tags('some','options');?&gt;</code> to list the tags. Try to find this function or a similar tag listing function. It's often either in <code>wp-content/themes/[CurrentTheme]/index.php</code> or <code>wp-content/themes/[CurrentTheme]/single.php</code>. Once you find it add the following code:<br /> 
	<code>&lt;?php if(function_exists('wp_folksonomy_add_form')) wp_folksonomy_add_form();?&gt;</code><br />
	immediately after it. Sorry it's not as automatic as I'd like it but feel free to ask any questions on the <a href="http://scott.sherrillmix.com/blog/programmer/web/WP_Folksonomy/">plugin website</a> (suggestions and bug reports are also greatly appreciated). Have fun cooperatively tagging.</p></div>
	</div>
<?php

}

add_action('wp_print_scripts', 'wp_folksonomy_add_js_libs');
function wp_folksonomy_add_js_libs() {
	global $wp_scripts;
	$options=wp_folksonomy_get_options();
	if(is_single()&&$options['javascript']!='plain'){
		//wp_enqueue_script('suggest');	
		wp_enqueue_script('wp_folksonomy',WP_FOLKSONOMY_FILE."&wp_folksonomy_js=true",array('suggest'));
	}
}

add_action('wp_head','wp_folksonomy_head');
function wp_folksonomy_head(){
	$options=wp_folksonomy_get_options();
	if(is_single()&&$options['javascript']!='plain'){
	?>
<style type="text/css">
.ac_results {padding: 0;margin: 0;list-style: none;position: absolute;z-index: 10000;display: none;border-width: 1px;border-style: solid;}
.ac_results li {padding: 2px 5px;	white-space: nowrap;text-align: left;}
.ac_over {cursor: pointer;}
.ac_match {text-decoration: underline;}
.ac_match {color:#000000;}
.ac_over {background-color:#F0F0B8;}
.ac_results {background-color:#FFFFFF;border-color:#808080;}
.ac_results li {color:#101010;}
</style>
	<?php
	}
}


?>