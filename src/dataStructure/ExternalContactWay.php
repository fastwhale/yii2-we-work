<?php

	namespace fastwhale\yii2\weWork\src\dataStructure;

	use fastwhale\yii2\weWork\components\Utils;

	/**
	 * Class ExternalContactWay
	 *
	 * @property string  $config_id          新增联系方式的配置id
	 * @property int     $type               联系方式类型,1-单人, 2-多人
	 * @property int     $scene              场景，1-在小程序中联系，2-通过二维码联系
	 * @property int     $style              在小程序中联系时使用的控件样式，详见附表
	 * @property string  $remark             联系方式的备注信息，用于助记，不超过30个字符
	 * @property boolean $skip_verify        外部客户添加时是否无需验证，默认为true
	 * @property string  $state              企业自定义的state参数，用于区分不同的添加渠道，在调用“获取外部联系人详情”时会返回该参数值
	 * @property array   $user               使用该联系方式的用户userID列表，在type为1时为必填，且只能有一个
	 * @property array   $party              使用该联系方式的部门id列表，只在type为2时有效
	 * @property boolean $is_temp            是否临时会话模式，true表示使用临时会话模式，默认为false
	 * @property int     $expires_in         临时会话二维码有效期，以秒为单位。该参数仅在is_temp为true时有效，默认7天，最多为14天
	 * @property int     $chat_expires_in    临时会话有效期，以秒为单位。该参数仅在is_temp为true时有效，默认为添加好友后24小时，最多为14天
	 * @property string  $unionid            可进行临时会话的客户unionid，该参数仅在is_temp为true时有效，如不指定则不进行限制
	 * @property boolean $is_exclusive       是否开启同一外部企业客户只能添加同一个员工，默认为否，开启后，同一个企业的客户会优先添加到同一个跟进人
	 * @property array   $conclusions        结束语，会话结束时自动发送给客户，可参考“结束语定义”，仅在is_temp为true时有效
	 * @property string  $qr_code            联系二维码的URL，仅在scene为2时返回
	 *
	 * @package fastwhale\yii2\weWork\src\dataStructure
	 */
	class ExternalContactWay
	{
		const TYPE_ONLY = 1;
		const TYPE_MORE = 2;

		const MINIPROGRAM_SCENE = 1;
		const QRCODE_SCENE      = 2;

		/**
		 * @param $arr
		 *
		 * @return ExternalContactWay
		 */
		public static function parseFromArray ($arr)
		{
			$externalContactWay = new ExternalContactWay();

			$externalContactWay->config_id   = Utils::arrayGet($arr, 'config_id');
			$externalContactWay->type        = Utils::arrayGet($arr, 'type', self::TYPE_ONLY);
			$externalContactWay->scene       = Utils::arrayGet($arr, 'scene', self::QRCODE_SCENE);
			$externalContactWay->style       = Utils::arrayGet($arr, 'style', 1);
			$externalContactWay->remark      = Utils::arrayGet($arr, 'remark');
			$externalContactWay->skip_verify = Utils::arrayGet($arr, 'skip_verify', true);
			$externalContactWay->state       = Utils::arrayGet($arr, 'state');
			$externalContactWay->user        = Utils::arrayGet($arr, 'user', []);
			$externalContactWay->party       = Utils::arrayGet($arr, 'party', []);
			$externalContactWay->is_temp     = Utils::arrayGet($arr, 'is_temp', false);
			if ($externalContactWay->is_temp) {
				$externalContactWay->expires_in      = Utils::arrayGet($arr, 'expires_in');
				$externalContactWay->chat_expires_in = Utils::arrayGet($arr, 'chat_expires_in');
				$externalContactWay->unionid         = Utils::arrayGet($arr, 'unionid');
				$externalContactWay->conclusions     = Utils::arrayGet($arr, 'conclusions', []);
			}
			$externalContactWay->is_exclusive = Utils::arrayGet($arr, 'is_exclusive', false);
			$externalContactWay->qr_code      = Utils::arrayGet($arr, 'qr_code');

			return $externalContactWay;
		}

		/**
		 * @param ExternalContactWay $externalContactWay
		 *
		 * @throws \ParameterError
		 */
		public static function CheckExternalContactWayAddArgs ($externalContactWay)
		{
			Utils::checkIsUInt($externalContactWay->type, 'external contact type');
			Utils::checkIsUInt($externalContactWay->scene, 'external contact scene');

			if ($externalContactWay->type == ExternalContactWay::TYPE_ONLY) {
				Utils::checkNotEmptyArray($externalContactWay->user, 'external contact user');

				if (is_array($externalContactWay->user) && count($externalContactWay->user) > 1) {
					throw new \ParameterError('user only can be one');
				}
			}

			if ($externalContactWay->type == ExternalContactWay::TYPE_MORE) {
				if (!Utils::notEmptyArray($externalContactWay->user) && !Utils::notEmptyArray($externalContactWay->party)) {
					throw new \ParameterError('error input parameter.');
				}
			}
		}

		/**
		 * @param ExternalContactWay $externalContactWay
		 *
		 * @throws \ParameterError
		 */
		public static function CheckExternalContactWayUpdateArgs ($externalContactWay)
		{
			Utils::checkNotEmptyStr($externalContactWay->config_id, 'external contact config_id');
		}
	}