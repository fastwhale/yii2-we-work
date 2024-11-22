<?php
	/**
	 * Create by PhpStorm
	 * User: fastwhale
	 * Date: 2020/1/13
	 * Time: 11:50
	 */

	namespace fastwhale\yii2\weWork\src\dataStructure;

	use fastwhale\yii2\weWork\components\Utils;

	/**
	 * Class TemplateCardMesssageContent
	 *
	 * @property string $msgtype                    消息类型，此时固定为：template_card
	 * @property array  $card_type                  模板卡片类型，文本通知型卡片填写 "text_notice"
	 * @property array  $source                     卡片来源样式信息，不需要来源样式可不填写
	 * @property array  $action_menu                卡片右上角更多操作按钮
	 * @property string $task_id                    任务id，同一个应用任务id不能重复，只能由数字、字母和“_-@”组成，最长128字节，填了action_menu字段的话本字段必填
	 * @property array  $main_title                 一级标题，建议不超过36个字，文本通知型卡片本字段非必填，但不可本字段和sub_title_text都不填，（支持id转译）
	 * @property array  $quote_area                 引用文献样式
	 * @property array  $emphasis_content           关键数据样式
	 * @property array  $horizontal_content_list    二级标题+文本列表，该字段可为空数组，但有数据的话需确认对应字段是否必填，列表长度不超过6
	 * @property array  $jump_list                  跳转指引样式的列表，该字段可为空数组，但有数据的话需确认对应字段是否必填，列表长度不超过3
	 * @property array  $card_action                整体卡片的点击跳转事件，text_notice必填本字段
	 *
	 * @package fastwhale\yii2\weWork\src\dataStructure
	 */
	class TemplateCardMesssageContent
	{
		const MSG_TYPE = 'template_card';

		/**
		 * @param array $arr
		 *
		 * @return TemplateCardMesssageContent
		 */
		public static function parseFromArray ($arr)
		{
			$text = new TemplateCardMesssageContent();

			$text->msgtype                 = static::MSG_TYPE;
			$text->card_type               = Utils::arrayGet($arr, 'card_type');
			$text->source                  = Utils::arrayGet($arr, 'source');
			$text->action_menu             = Utils::arrayGet($arr, 'action_menu');
			$text->task_id                 = Utils::arrayGet($arr, 'task_id');
			$text->main_title              = Utils::arrayGet($arr, 'main_title');
			$text->quote_area              = Utils::arrayGet($arr, 'quote_area');
			$text->emphasis_content        = Utils::arrayGet($arr, 'emphasis_content');
			$text->horizontal_content_list = Utils::arrayGet($arr, 'horizontal_content_list');
			$text->jump_list               = Utils::arrayGet($arr, 'jump_list');
			$text->card_action             = Utils::arrayGet($arr, 'card_action');

			return $text;
		}

		/**
		 * @param TemplateCardMesssageContent $content
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public static function CheckMessageSendArgs ($content)
		{
			Utils::checkNotEmptyStr($content->msgtype, 'msgtype');

			if ($content->msgtype != static::MSG_TYPE) {
				throw new \QyApiError("msgtype invalid.");
			}

			Utils::checkNotEmptyStr($content->card_type, 'card_type');
			Utils::checkNotEmptyArray($content->card_action, 'card_action');
			Utils::checkNotEmptyStr($content->task_id, 'task_id');
		}

		/**
		 * @param TemplateCardMesssageContent $content
		 * @param                             $arr
		 */
		public static function MessageContent2Array ($content, &$arr)
		{
			Utils::setIfNotNull($content->msgtype, "msgtype", $arr);

			$args = [];
			Utils::setIfNotNull($content->card_type, "card_type", $args);
			Utils::setIfNotNull($content->source, "source", $args);
			Utils::setIfNotNull($content->action_menu, "action_menu", $args);
			Utils::setIfNotNull($content->task_id, "task_id", $args);
			Utils::setIfNotNull($content->main_title, "main_title", $args);
			Utils::setIfNotNull($content->quote_area, "quote_area", $args);
			Utils::setIfNotNull($content->emphasis_content, "emphasis_content", $args);
			Utils::setIfNotNull($content->horizontal_content_list, "horizontal_content_list", $args);
			Utils::setIfNotNull($content->jump_list, "jump_list", $args);
			Utils::setIfNotNull($content->card_action, "card_action", $args);

			Utils::setIfNotNull($args, $content->msgtype, $arr);

		}
	}