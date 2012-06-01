<?php
/**
 * WeNotif-Quote's main plugin file
 * Just as a note, this is fairly similar to WeNotif-TopicReply because I coped the entire file :P
 * 
 * @package Dragooon:WeNotif-Quote
 * @author Shitiz "Dragooon" Garg <Email mail@dragooon.net> <Url http://smf-media.com>
 * @copyright 2012, Shitiz "Dragooon" Garg <mail@dragooon.net>
 * @license
 *		Licensed under "New BSD License (3-clause version)"
 *		http://www.opensource.org/licenses/BSD-3-Clause
 * @version 1.0
 */

if (!defined('WEDGE'))
	die('File cannot be requested directly');

/**
 * Removes all nested quotes to leave only the top level one
 *
 * @access public
 * @param string $str The message body
 * @return string
 */
function remove_nested_quotes($str)
{
    $blocks = preg_split('/(\[quote.*?\]|\[\/quote\])/i', $str, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    
    $quote_level = 0;
    $message = '';
        
    foreach ($blocks as $block)
    {
        if (preg_match('/\[quote(.*)?\]/i', $block, $matches))
        {
            if ($quote_level == 0)
            	$message .= '[quote' . $matches[1] . ']';
            $quote_level++;
        }
        elseif (preg_match('/\[\/quote\]/i', $block))
        {
            if ($quote_level <= 1)
            	$message .= '[/quote]';
            if ($quote_level >= 1)
            {
                $quote_level--;
                $message .= "\n";
            }
        }
        elseif ($quote_level <= 1)
        	$message .= $block;
    }
    
    return $message;
}

/**
 * Callback for the hook, "notification_callback", registers this as a verified notifier for notifications
 *
 * @param array &$notifiers
 * @return void
 */
function wenotif_quote_callback(array &$notifiers)
{
	$notifiers['quote'] = new QuoteNotifier();
}

/**
 * Callback for the hook, "create_post_after", actually issues the notification
 *
 * @param array $msgOptions
 * @param array $topicOptions
 * @param array $posterOptions
 * @param bool $new_topic
 * @return void
 */
function wenotif_quote_create_post_after(&$msgOptions, &$topicOptions, &$posterOptions, &$new_topic)
{
	// Don't bother with new topics or if the poster's the current member
	if ($new_topic)
		return;

	$body = $msgOptions['body'];
	$body = remove_nested_quotes($body);
	preg_match_all('/\[quote.*?link=msg=([0-9]+).*?\]/i', $body, $matches);

	// No quotes? How how unlucky...
	if (empty($matches[1]))
		return true;

	$id_msgs = $matches[1];
	foreach ($id_msgs as $k => $id_msg)
		$id_msgs[$k] = (int) $id_msg;
	
	// Get the messages
	$request = wesql::query('
		SELECT m.id_member
		FROM {db_prefix}messages AS m
		WHERE id_msg IN ({array_int:msgs})
		LIMIT {int:count}',
		array(
			'msgs' => array_unique($id_msgs),
			'count' => count(array_unique($id_msgs)),
		)
	);

	$done_members = array();
	while (list ($id_member) = wesql::fetch_row($request))
	{
		// Quoting themself Or notified already?
		if ($posterOptions['id'] == $id_member || in_array($id_member, $done_members))
			continue;
		
		$done_members[] = $id_member;

		// Issue the notification
		// Even though it is linked per-msg, we create it against topic for performance
		Notification::issue($id_member, WeNotif::getNotifiers('quote'), $topicOptions['id'],
							array('subject' => $msgOptions['subject'], 'id_msg' => $msgOptions['id'], 'member' => $posterOptions['name']));
	}

	wesql::free_result($request);
}

/**
 * Hook callback for "displaay_main", doesn't do much except mark the topic's quote notifications as read
 *
 * @return void
 */
function wenotif_quote_display_main()
{
	global $topic, $user_info;

	Notification::markReadForNotifier($user_info['id'], WeNotif::getNotifiers('quote'), $topic);
}

class QuoteNotifier implements Notifier
{
	/**
	 * Constructor, loads the language file for some strings we use
	 *
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		loadPluginLanguage('Dragooon:WeNotif-Quote', 'plugin');
	}

	/**
	 * Callback for getting the URL of the object
	 *
	 * @access public
	 * @param Notification $notification
	 * @return string A fully qualified HTTP URL
	 */
	public function getURL(Notification $notification)
	{
		global $scripturl;

		$data = $notification->getData();

		return $scripturl . '?topic=' . $notification->getObject() . '.msg' . $data['id_msg'] . '#msg' . $data['id_msg'];
	}

	/**
	 * Callback for getting the text to display on the notification screen
	 *
	 * @access public
	 * @param Notification $notification
	 * @return string The text this notification wants to display
	 */
	public function getText(Notification $notification)
	{
		global $txt;

		$data = $notification->getData();

		// Only one member?
		return sprintf($txt['notification_quote'], $data['member'], shorten_subject($data['subject'], 25));
	}

	/**
	 * Returns the name of this notifier
	 *
	 * @access public
	 * @return string
	 */
	public function getName()
	{
		return 'quote';
	}

	/**
	 * Callback for handling multiple notifications on the same object
	 * We don't have any special criterion for this
	 *
	 * @access public
	 * @param Notification $notification
	 * @param array &$data Reference to the new notification's data, if something needs to be altered
	 * @return bool, if false then a new notification is not created but the current one's time is updated
	 */
	public function handleMultiple(Notification $notification, array &$data)
	{
		return true;
	}

	/**
	 * Returns the elements for notification's profile area
	 *
	 * @access public
	 * @param int $id_member The ID of the member whose profile is currently being accessed
	 * @return array(title, description, config_vars)
	 */
	public function getProfile($id_member)
	{
		global $txt;

		return array($txt['notification_quote_profile'], $txt['notification_quote_profile_desc'], array());
	}

	/**
	 * Callback for profile area, called when saving the profile area
	 *
	 * @access public
	 * @param int $id_member The ID of the member whose profile is currently being accessed
	 * @param array $settings A key => value pair of the fed settings
	 * @return void
	 */
	public function saveProfile($id_member, array $settings)
	{
	}

	/**
	 * E-mail handler
	 *
	 * @access public
	 * @param Notification $notification
	 * @param array $email_data Any additional e-mail data passed to Notification::issue function
	 * @return array(subject, body)
	 */
	public function getEmail(Notification $notification, array $email_data)
	{
		global $txt;

		return array($txt['notification_quote_email_subject'], $this->getText($notification));
	}
}