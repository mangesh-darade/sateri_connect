<?php
// Rows of the carts table (also returned by Settings::cartRemindersData for live refresh).
$canEdit = ! empty($canEdit);
$crPlats = \App\Libraries\CartRecoveryService::PLATFORMS;
?>
                        <?php if (empty($cartOverview['rows'])): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No carts yet. They appear when your store sends an event.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($cartOverview['rows'] as $c): ?>
                            <?php $badge = ['open' => 'warning', 'reminded' => 'info', 'recovered' => 'success', 'skipped' => 'secondary'][$c['status']] ?? 'secondary'; ?>
                            <tr>
                                <td><?= esc($crPlats[$c['platform']] ?? $c['platform']) ?></td>
                                <td><?= esc($c['customer_name'] !== '' ? $c['customer_name'] : '-') ?><div class="small text-muted"><?= esc($c['phone'] !== '' ? $c['phone'] : 'No phone') ?></div></td>
                                <td class="small"><?= esc($c['items_text']) ?></td>
                                <td class="text-end"><?= esc($c['total']) ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= esc(ucfirst((string) $c['status'])) ?></span><?php if ($c['last_error'] !== ''): ?><div class="small text-muted"><?= esc($c['last_error']) ?></div><?php endif; ?>
                                    <?php if (! empty($c['delivery_status'])): ?>
                                        <?php $dl = ['sent' => ['success', 'Delivered to WhatsApp'], 'failed' => ['danger', 'Delivery failed'], 'cancelled' => ['secondary', 'Cancelled']][$c['delivery_status']] ?? ['info', 'Sending']; ?>
                                        <div class="small text-<?= $dl[0] ?>"><?= esc($dl[1]) ?><?= ($c['delivery_status'] === 'failed' && (string) $c['delivery_error'] !== '') ? ': ' . esc(\App\Libraries\CartRecoveryService::deliveryHint((string) $c['delivery_error'])) : '' ?></div>
                                    <?php endif; ?></td>
                                <td class="text-end"><?= (int) $c['reminders_sent'] ?></td>
                                <td class="small"><?= esc(format_app_datetime($c['last_activity_at'], 'd M Y, g:i A')) ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end text-nowrap" style="min-width:120px">
                                        <?php if ($c['status'] !== 'recovered' && $c['phone'] !== ''): ?>
                                            <button type="button" class="btn btn-wa btn-sm js-cr-send" data-id="<?= (int) $c['id'] ?>" title="Send the reminder now"><i class="fas fa-paper-plane me-1"></i> Send now</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
