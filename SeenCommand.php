<?php
/**
 * This Command is a drop-in script for the the PHP-TelegramBot package.
 *
 * Fill in $bot_owner if you do any tinkering, otherwise it should never appear.
 *
 * (copyleft) @k_fox of https://furry.media
 * https://twitter.com/furry_media
 * https://github.com/furrymedia/php-telegram-bot-commands/
 */

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;

use Exception;
use Longman\TelegramBot\Exception\TelegramException;
use PDO;

class SeenCommand extends UserCommand
{
	/**
	* @var string
	*/
	protected $name = 'seen';

	/**
	* @var string
	*/
	protected $description = 'Show the last time a user was seen and their message.';

	/**
	* @var string
	*/
	protected $usage = '/seen [(@)username|full name|first name|user id number]';

	/**
	* @var string
	*/
	protected $version = '0.99';

	/**
	* Command execute method
	*
	* @return \Longman\TelegramBot\Entities\ServerResponse
	* @throws \Longman\TelegramBot\Exception\TelegramException
	*/

	public function execute()
	{
		$bot_owner = 'I DON\'T READ DOCUMENTATION :D';
		$dbh = DB::getPdo();

		$message = $this->getMessage();
		$chat_id = $message->getChat()->getId();
		$raw_input  = trim($message->getText(true));

		$data =
		[
			'chat_id' => $chat_id,
			'parse_mode' => 'HTML'
		];

		if($raw_input == '')
		{
			$data['text'] = 'Command usage: ' . $this->getUsage();
			return Request::sendMessage($data);
		}
		else
		{
			$username = false;
			if(substr($raw_input, 0, 1) == '@')
			{
				$username = true;
				$input = str_replace('@', '', $raw_input);
			}
			else
			{
				$input = $raw_input;
			}

			$username_st = $dbh->prepare("select * from `user` where `username` = ?");
			$uid_st = $dbh->prepare("select * from `user` where `id` = ?");
			$full_st = $dbh->prepare("select *, CONCAT(`first_name`, ' ', `last_name`) from `user` where CONCAT(`first_name`, ' ', `last_name`) LIKE ?");
			$first_st = $dbh->prepare("select * from `user` where `first_name` LIKE ?");

			if(is_numeric($input))
			{
				$uid_st->execute([$input]);
				$uid_row = $uid_st->fetch();

				if(!$uid_row)
				{
					$data['text']  = "Unable to find user with UID '{$input}', maybe I haven't seen them speak before. If you meant to search by username/full name instead be sure to specify more than numbers.";
					return Request::sendMessage($data);
				}
				else
				{
					$user_row = $uid_row;
				}
			}
			else
			{
				$username_st->execute([$input]);
				$username_row = $username_st->fetch();

				if(!$username_row)
				{
					if($username)
					{
						$data['text']  = "Unable to find user with username '{$input}',  maybe I haven't seen them speak before. Try searching by their full name (what you see when they type) or their UID instead.";
						return Request::sendMessage($data);
					}
					else
					{
						$full_st->execute([$input]);
						$full_row = $full_st->fetch();

						if(!$full_row)
						{
							$first_st->execute([$input]);
							$first_row = $first_st->fetch();

							if(!$first_row)
							{
								$data['text']  = "Unable to find user with username/full name/UID '{$input}',  maybe I haven't seen them speak before.";
								return Request::sendMessage($data);
							}
							else
							{
								$user_row = $first_row;
							}
						}
						else
						{
							$user_row = $full_row;
						}
					}
				}
				else
				{
					$user_row = $username_row;
				}
			}

			if(!$user_row)
			{
				$data['text']  = "No matches for '$input' were found, but there was an unhandled exception in pattern matching. Please forward this message to $bot_owner";
				return Request::sendMessage($data);
			}

			$message_st = $dbh->prepare("select * from `message` where `user_id` = ? and `chat_id` < 0 order by `date` desc");
			$message_st->execute([$user_row['id']]);
			$message_row = $message_st->fetch();

			if(!$message_row)
			{
				$data['text']  = "I know who '$input' is but I can't find any messages from them, it's possible they haven't spoken since I met them or their last message was so long ago it has been purged from memory. Try contacting them directly with a private message to @".htmlspecialchars($user_row['username']);
				return Request::sendMessage($data);
			}
			else
			{
				$chat_st = $dbh->prepare("select * from `chat` where `id` = ?");
				$chat_st->execute([$message_row['chat_id']]);
				$chat_row = $chat_st->fetch();

				if(!$chat_row)
				{
					$data['text']  = "I know who '$input' is but I don't recognise the group I last saw them in, it's possible I'm not there anymore. In case the group is private I won't tell you their last message, but you can try contacting them directly with a private message to @".htmlspecialchars($user_row['username']);
					return Request::sendMessage($data);
				}
				else
				{
					$full_name = $user_row['last_name'] != '' ? $user_row['first_name'].' '.$user_row['last_name'] : $user_row['first_name'];
					$chat_name = $chat_row['username'] == '' ? htmlspecialchars($chat_row['title']) : htmlspecialchars($chat_row['title']).' (@'.htmlspecialchars($chat_row['username']).')';
					$chat_name = $chat_row['type'] == 'private' ? '[PRIVATE]' : $chat_name;

					$text = htmlspecialchars($full_name).' (@'.htmlspecialchars($user_row['username']).") was last seen at ".htmlspecialchars($message_row['date'])."ET\n<strong>Group:</strong> $chat_name";
					if(($chat_row['username'] != '' and $chat_row['type'] != 'private') or $chat_row['id'] == $chat_id)
					{
						if(trim($message_row['text']) == '')
						{
							$text .= "\nThier last message contained no text, it was probably a sticker or an image.";
						}
						else
						{
							$text .= "\n<strong>Last message:</strong> <em>".htmlspecialchars($message_row['text'])."</em>";
						}
					}
					else
					{
						$text .= "\nThis is a private chat. I will only show you their last message if you ask there.";
					}

					$data['text']  = $text;
					return Request::sendMessage($data);
				}
			}

		}
	}
}
