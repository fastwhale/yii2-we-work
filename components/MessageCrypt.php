<?php

	namespace fastwhale\yii2\weWork\components;

	require_once 'messageCrypt/WXBizMsgCrypt.php';

	/**
	 * Class MessageCrypt
	 * @package fastwhale\yii2\weWork\components
	 */
	class MessageCrypt extends \WXBizMsgCrypt
	{
		/**
		 * Returns the fully qualified name of this class.
		 * @return string The fully qualified name of this class.
		 */
		public static function className ()
		{
			return get_called_class();
		}
	}