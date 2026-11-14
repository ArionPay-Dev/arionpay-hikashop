<?php
/**
 * ArionPay for HikaShop: the page after "Confirm order". It sends the customer to the ArionPay payment page.
 * Override it in your template: templates/<template>/hikashoppayment/arionpay_end.php
 *
 * @license MIT
 */
defined('_JEXEC') or die('Restricted access');
?>
<div class="hikashop_arionpay_end" id="hikashop_arionpay_end">
<?php if (!empty($this->arionpay_url)) : ?>
	<p class="hikashop_arionpay_end_message">
		<?php echo \Joomla\CMS\Language\Text::sprintf('PLEASE_WAIT_BEFORE_REDIRECTION_TO_X', htmlspecialchars((string) $this->payment_name, ENT_QUOTES, 'UTF-8')); ?>
	</p>
	<p>
		<a id="hikashop_arionpay_button" class="btn btn-primary" href="<?php echo htmlspecialchars($this->arionpay_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo \Joomla\CMS\Language\Text::_('PAY_NOW'); ?></a>
	</p>
	<script type="text/javascript">
		window.location.href = <?php echo json_encode($this->arionpay_url); ?>;
	</script>
<?php else : ?>
	<p class="hikashop_arionpay_end_error"><?php echo htmlspecialchars((string) $this->arionpay_error, ENT_QUOTES, 'UTF-8'); ?></p>
	<p><a class="btn btn-secondary" href="<?php echo htmlspecialchars((string) $this->arionpay_done_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo \Joomla\CMS\Language\Text::_('HIKASHOP_CHECKOUT_END'); ?></a></p>
<?php endif; ?>
</div>
