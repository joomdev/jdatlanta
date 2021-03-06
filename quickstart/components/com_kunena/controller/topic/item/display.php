<?php
/**
 * Kunena Component
 * @package     Kunena.Site
 * @subpackage  Controller.Topic
 *
 * @copyright   (C) 2008 - 2018 Kunena Team. All rights reserved.
 * @license     https://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link        https://www.kunena.org
 **/
defined('_JEXEC') or die;

/**
 * Class ComponentKunenaControllerTopicItemDisplay
 *
 * @since  K4.0
 */
class ComponentKunenaControllerTopicItemDisplay extends KunenaControllerDisplay
{
	protected $name = 'Topic/Item';

	/**
	 * @var KunenaUser
	 */
	public $me;

	/**
	 * @var KunenaForumCategory
	 */
	public $category;

	/**
	 * @var KunenaForumTopic
	 */
	public $topic;

	/**
	 * @var KunenaPagination
	 */
	public $pagination;

	/**
	 * @var string
	 */
	public $headerText;

	/**
	 * Prepare topic display.
	 *
	 * @return void
	 *
	 * @throws KunenaExceptionAuthorise
	 */
	protected function before()
	{
		parent::before();

		$catid = $this->input->getInt('catid', 0);
		$id = $this->input->getInt('id', 0);
		$mesid = $this->input->getInt('mesid', 0);
		$start = $this->input->getInt('limitstart', 0);
		$limit = $this->input->getInt('limit', 0);

		if ($limit < 1 || $limit > 100)
		{
			$limit = $this->config->messages_per_page;
		}

		$this->me = KunenaUserHelper::getMyself();

		$allowed = md5(serialize(KunenaAccess::getInstance()->getAllowedCategories()));
		$cache   = JFactory::getCache('com_kunena', 'output');

		/*if ($cache->start("{$this->ktemplate->name}.common.jump.{$allowed}", 'com_kunena.template'))
		 {
		 return;
		 }*/

		$options            = array();
		$options []         = JHtml::_('select.option', '0', JText::_('COM_KUNENA_FORUM_TOP'));
		$cat_params         = array('sections' => 1, 'catid' => 0);
		$this->categorylist = JHtml::_('kunenaforum.categorylist', 'catid', 0, $options, $cat_params, 'class="inputbox fbs" size="1" onchange = "this.form.submit()"', 'value', 'text');


		// Load topic and message.
		if ($mesid)
		{
			// If message was set, use it to find the current topic.
			$this->message = KunenaForumMessageHelper::get($mesid);
			$this->topic = $this->message->getTopic();
		}
		else
		{
			// Note that redirect loops throw RuntimeException because of we added KunenaForumTopic::getTopic() call!
			$this->topic = KunenaForumTopicHelper::get($id)->getTopic();
			$this->message = KunenaForumMessageHelper::get($this->topic->first_post_id);
		}

		// Load also category (prefer the URI variable if available).
		if ($catid && $catid != $this->topic->category_id)
		{
			$this->category = KunenaForumCategoryHelper::get($catid);
			$this->category->tryAuthorise();
		}
		else
		{
			$this->category = $this->topic->getCategory();
		}

		// Access check.
		$this->message->tryAuthorise();

		// Check if we need to redirect (category or topic mismatch, or resolve permanent URL).
		if ($this->primary)
		{
			$channels = $this->category->getChannels();

			if ($this->message->thread != $this->topic->id
				|| ($this->topic->category_id != $this->category->id && !isset($channels[$this->topic->category_id])))
			{
				$this->app->redirect($this->message->getUrl(null, false));
			}
		}

		// Load messages from the current page and set the pagination.
		$hold = KunenaAccess::getInstance()->getAllowedHold($this->me, $this->category->id, false);
		$finder = new KunenaForumMessageFinder;
		$finder
			->where('thread', '=', $this->topic->id)
			->filterByHold($hold);

		$start = $mesid ? $this->topic->getPostLocation($mesid) : $start;
		$this->pagination = new KunenaPagination($finder->count(), $start, $limit);

		$this->messages = $finder
			->order('time', $this->me->getMessageOrdering() == 'asc' ? 1 : - 1)
			->start($this->pagination->limitstart)
			->limit($this->pagination->limit)
			->find();

		$this->prepareMessages($mesid);

		$doc = JFactory::getDocument();

		if ($this->topic->unread)
		{
			$doc->setMetaData('robots', 'noindex, follow');
		}

		if (!$start)
		{
			foreach ($doc->_links as $key => $value)
			{
				if (is_array($value))
				{
					if (array_key_exists('relation', $value))
					{
						if ($value['relation'] == 'canonical')
						{
							$canonicalUrl = $this->topic->getUrl();
							$doc->_links[$canonicalUrl] = $value;
							unset($doc->_links[$key]);
							break;
						}
					}
				}
			}
		}

		// Run events.
		$params = new JRegistry;
		$params->set('ksource', 'kunena');
		$params->set('kunena_view', 'topic');
		$params->set('kunena_layout', 'default');

		$dispatcher = JEventDispatcher::getInstance();
		JPluginHelper::importPlugin('kunena');

		$dispatcher->trigger('onKunenaPrepare', array ('kunena.topic', &$this->topic, &$params, 0));
		$dispatcher->trigger('onKunenaPrepare', array ('kunena.messages', &$this->messages, &$params, 0));

		// Get user data, captcha & quick reply.
		$this->userTopic = $this->topic->getUserTopic();
		$this->quickReply = ($this->topic->isAuthorised('reply') && $this->me->exists());

		$this->headerText = html_entity_decode($this->topic->displayField('subject'));
	}

	/**
	 * Prepare messages for display.
	 *
	 * @param   int  $mesid  Selected message Id.
	 *
	 * @return  void
	 */
	protected function prepareMessages($mesid)
	{
		// Get thank yous for all messages in the page
		$thankyous = KunenaForumMessageThankyouHelper::getByMessage($this->messages);

		// First collect ids and users.
		$threaded = ($this->layout == 'indented' || $this->layout == 'threaded');
		$userlist = array();
		$this->threaded = array();
		$location = $this->pagination->limitstart;

		foreach ($this->messages AS $message)
		{
			$message->replynum = ++$location;

			if ($threaded)
			{
				// Threaded ordering
				if (isset($this->messages[$message->parent]))
				{
					$this->threaded[$message->parent][] = $message->id;
				}
				else
				{
					$this->threaded[0][] = $message->id;
				}
			}

			$userlist[(int) $message->userid] = (int) $message->userid;
			$userlist[(int) $message->modified_by] = (int) $message->modified_by;

			$thankyou_list = $thankyous[$message->id]->getList();
			$message->thankyou = array();

			if (!empty($thankyou_list))
			{
				$message->thankyou = $thankyou_list;
			}
		}

		if (!isset($this->messages[$mesid]) && !empty($this->messages))
		{
			$this->message = reset($this->messages);
		}

		if ($threaded)
		{
			if (!isset($this->messages[$this->topic->first_post_id]))
			{
				$this->messages = $this->getThreadedOrdering(0, array('edge'));
			}
			else
			{
				$this->messages = $this->getThreadedOrdering();
			}
		}

		// Prefetch all users/avatars to avoid user by user queries during template iterations
		KunenaUserHelper::loadUsers($userlist);

		// Prefetch attachments.
		KunenaAttachmentHelper::getByMessage($this->messages);
	}

	/**
	 * Change ordering of the displayed messages and apply threading.
	 *
	 * @param   int    $parent  Parent Id.
	 * @param   array  $indent  Indent for the current object.
	 *
	 * @return  array
	 */
	protected function getThreadedOrdering($parent = 0, $indent = array())
	{
		$list = array();

		if (count($indent) == 1 && $this->topic->getTotal() > $this->pagination->limitstart + $this->pagination->limit)
		{
			$last = -1;
		}
		else
		{
			$last = end($this->threaded[$parent]);
		}

		foreach ($this->threaded[$parent] as $mesid)
		{
			$message = $this->messages[$mesid];
			$skip = $message->id != $this->topic->first_post_id
				&& $message->parent != $this->topic->first_post_id && !isset($this->messages[$message->parent]);

			if ($mesid != $last)
			{
				// Default sibling edge
				$indent[] = 'crossedge';
			}
			else
			{
				// Last sibling edge
				$indent[] = 'lastedge';
			}

			end($indent);
			$key = key($indent);

			if ($skip)
			{
				$indent[] = 'gap';
			}

			$list[$mesid] = $this->messages[$mesid];
			$list[$mesid]->indent = $indent;

			if (empty($this->threaded[$mesid]))
			{
				// No children node
				// FIXME: $mesid == $message->thread
				$list[$mesid]->indent[] = ($mesid == $message->thread) ? 'single' : 'leaf';
			}
			else
			{
				// Has children node
				// FIXME: $mesid == $message->thread
				$list[$mesid]->indent[] = ($mesid == $message->thread) ? 'root' : 'node';
			}

			if (!empty($this->threaded[$mesid]))
			{
				// Fix edges
				if ($mesid != $last)
				{
					$indent[$key] = 'edge';
				}
				else
				{
					$indent[$key] = 'empty';
				}

				if ($skip)
				{
					$indent[$key + 1] = 'empty';
				}

				$list += $this->getThreadedOrdering($mesid, $indent);
			}

			if ($skip)
			{
				array_pop($indent);
			}

			array_pop($indent);
		}

		return $list;
	}

	/**
	 * After render update topic data for the user.
	 *
	 * @return void
	 */
	protected function after()
	{
		parent::after();

		$this->topic->hit();
		$this->topic->markRead();

		// Check if subscriptions have been sent and reset the value.
		if ($this->topic->isAuthorised('subscribe') && $this->userTopic->subscribed == 2)
		{
			$this->userTopic->subscribed = 1;
			$this->userTopic->save();
		}
	}

	/**
	 * Prepare document.
	 *
	 * @return void
	 */
	protected function prepareDocument()
	{
		$doc = JFactory::getDocument();
		$doc->setMetaData('og:type', 'article', 'property');
		$doc->setMetaData('og:title', $this->topic->displayField('subject'), 'property');
		$doc->setMetaData('og:author', $this->topic->getAuthor()->username, 'property');
		$doc->setMetaData('article:published_time', $this->topic->getFirstPostTime()->toKunena('config_post_dateformat'), 'property');
		$doc->setMetaData('article:section', $this->topic->getCategory()->name, 'property');

		$config = JFactory::getConfig();
		$robots = $config->get('robots');

		if ($robots == '')
		{
			$doc->setMetaData('robots', 'index, follow');
		}
		elseif ($robots == 'noindex, follow')
		{
			$doc->setMetaData('robots', 'noindex, follow');
		}
		elseif ($robots == 'index, nofollow')
		{
			$doc->setMetaData('robots', 'index, nofollow');
		}
		else
		{
			$doc->setMetaData('robots', 'nofollow, noindex');
		}

		$page = $this->pagination->pagesCurrent;
		$total = $this->pagination->pagesTotal;
		$headerText = $this->headerText . ($total > 1 && $page > 1 ? " - " . JText::_('COM_KUNENA_PAGES') . " {$page}" : '');

		$pagdata = $this->pagination->getData();

		if ($pagdata->previous->link)
		{
			$pagdata->previous->link = str_replace( '?limitstart=0', '', $pagdata->previous->link);
			$doc->addHeadLink($pagdata->previous->link, 'prev');
		}

		if ($pagdata->next->link)
		{
			$doc->addHeadLink($pagdata->next->link, 'next');
		}

		if ($page > 1)
		{
			foreach ($doc->_links as $key => $value)
			{
				if (is_array($value))
				{
					if (array_key_exists('relation', $value))
					{
						if ($value['relation'] == 'canonical')
						{
							$canonicalUrl = KunenaRoute::_();
							$doc->_links[$canonicalUrl] = $value;
							unset($doc->_links[$key]);
							break;
						}
					}
				}
			}
		}

		$app = JFactory::getApplication();
		$menu_item   = $app->getMenu()->getActive();

		if ($menu_item)
		{
			$params             = $menu_item->params;
			$params_keywords    = $params->get('menu-meta_keywords');

			$this->setTitle($headerText);

			if (!empty($params_keywords))
			{
				$keywords = $params->get('menu-meta_keywords');
				$this->setKeywords($keywords);
				$doc->setMetaData('article:tag', $keywords, 'property');
			}
			else
			{
				$this->setKeywords($headerText);
			}

			if ($total > 1 && $page > 1)
			{
				$small = KunenaHtmlParser::stripBBCode($this->topic->first_post_message, 140);

				if (empty($small))
				{
					$small = $headerText;
				}

				$this->setDescription($small . " - " . JText::_('COM_KUNENA_PAGES') . " {$page}");
			}
			else
			{
				$small = KunenaHtmlParser::stripBBCode($this->topic->first_post_message, 160);

				if (empty($small))
				{
					$small = $headerText;
				}

				$this->setDescription($small);
			}
		}
	}
}
