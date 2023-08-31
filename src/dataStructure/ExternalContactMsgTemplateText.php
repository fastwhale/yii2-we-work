<?php

	namespace fastwhale\yii2\weWork\src\dataStructure;

	use fastwhale\yii2\weWork\components\Utils;

	/**
	 * Class ExternalContactMsgTemplateText
	 *
	 * @property string $content 消息文本内容，最多4000个字节
	 *
	 * @package fastwhale\yii2\weWork\src\dataStructure
	 */
	class ExternalContactMsgTemplateText
	{
		/**
		 * @param array $arr
		 *
		 * @return ExternalContactMsgTemplateText
		 */
		public static function parseFromArray ($arr)
		{
			$textTemplate = new ExternalContactMsgTemplateText();

			$textTemplate->content = Utils::arrayGet($arr, 'content');

			return $textTemplate;
		}
	}