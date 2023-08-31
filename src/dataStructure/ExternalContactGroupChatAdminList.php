<?php

	namespace fastwhale\yii2\weWork\src\dataStructure;

	use fastwhale\yii2\weWork\components\Utils;

	/**
	 * Class ExternalContactGroupChatMemberList
	 *
	 * @property string $userid        群管理员userid
	 *
	 * @package fastwhale\yii2\weWork\src\dataStructure
	 */
	class ExternalContactGroupChatAdminList
	{
		/**
		 * @param array $arr
		 *
		 * @return ExternalContactGroupChatAdminList
		 */
		public static function parseFromArray ($arr)
		{
			$adminList = new ExternalContactGroupChatAdminList();

			$adminList->userid = Utils::arrayGet($arr, 'userid');

			return $adminList;
		}
	}