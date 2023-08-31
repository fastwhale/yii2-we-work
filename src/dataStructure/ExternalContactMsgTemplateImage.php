<?php

	namespace fastwhale\yii2\weWork\src\dataStructure;

	use fastwhale\yii2\weWork\components\Utils;

	/**
	 * Class ExternalContactMsgTemplateText
	 *
	 * @property string $media_id 图片的media_id
	 *
	 * @package fastwhale\yii2\weWork\src\dataStructure
	 */
	class ExternalContactMsgTemplateImage
	{
		/**
		 * @param array $arr
		 *
		 * @return ExternalContactMsgTemplateImage
		 */
		public static function parseFromArray ($arr)
		{
			$imgTemplate = new ExternalContactMsgTemplateImage();

			$imgTemplate->media_id = Utils::arrayGet($arr, 'media_id');

			return $imgTemplate;
		}
	}