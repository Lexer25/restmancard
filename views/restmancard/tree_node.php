<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Узел дерева restmancard: организация + её подразделения и сотрудники.
 *
 * @var array $node
 * @var int   $level
 */
$has_children = ! empty($node['CHILDREN']);
$has_people = ! empty($node['PEOPLE']);
$has_content = ($has_children OR $has_people);
$expanded = ! empty($node['EXPANDED']);

$node_name = $node['NAME'];
$search_name = $node_name . ' ' . $node['DIVCODE'];
?>
<li class="tree-node"
    data-type="org"
    data-uid="<?php echo HTML::chars($node['UID']); ?>"
    data-divcode="<?php echo HTML::chars($node['DIVCODE']); ?>"
    data-name="<?php echo HTML::chars($search_name); ?>"
    data-expanded="<?php echo $expanded ? 'true' : 'false'; ?>">
    <div class="tree-item tree-item-org" title="<?php echo HTML::chars($node['NAME']); ?>">
        <span class="tree-toggle">
            <span class="rc-folder" style="font-size: 16px;"><?php echo $expanded ? '📂' : '📁'; ?></span>
        </span>

        <span class="org-id">[<?php echo (int) $node['ID']; ?>]</span>

        <span class="item-name org-name"><?php echo HTML::chars($node_name); ?></span>

        <?php if ((int) $node['PEOPLE_COUNT'] > 0): ?>
            <span class="badge rc-badge-people" title="<?php echo __('Сотрудников'); ?>">👤 <?php echo (int) $node['PEOPLE_COUNT']; ?></span>
        <?php else: ?>
            <span class="badge badge-empty" title="<?php echo __('Сотрудников'); ?>">👤 0</span>
        <?php endif; ?>

        <?php if ((int) $node['CHILDREN_COUNT'] > 0): ?>
            <span class="badge rc-badge-children" title="<?php echo __('Подразделений'); ?>">📁 <?php echo (int) $node['CHILDREN_COUNT']; ?></span>
        <?php else: ?>
            <span class="badge badge-empty" title="<?php echo __('Подразделений'); ?>">📁 0</span>
        <?php endif; ?>
    </div>

    <ul class="tree-children" style="display: <?php echo ($expanded AND $has_content) ? 'block' : 'none'; ?>;">
        <?php foreach ($node['CHILDREN'] as $child): ?>
            <?php echo View::factory('restmancard/tree_node', array(
                'node'  => $child,
                'level' => $level + 1,
            )); ?>
        <?php endforeach; ?>

        <?php foreach ($node['PEOPLE'] as $person): ?>
            <?php
            $cards = $person['IDENTIFIERS'];
            $shown_cards = array_slice($cards, 0, 3);
            ?>
            <li class="tree-node"
                data-type="person"
                data-uid="<?php echo HTML::chars($person['UID']); ?>"
                data-tabnum="<?php echo HTML::chars($person['TABNUM']); ?>"
                data-name="<?php echo HTML::chars($person['FULL_NAME'] . ' ' . $person['TABNUM']); ?>">
                <div class="tree-item tree-item-person" title="<?php echo HTML::chars($person['FULL_NAME']); ?>">
                    <span class="tree-toggle">
                        <span style="font-size: 16px;">👤</span>
                    </span>

                    <span class="person-id">[<?php echo HTML::chars($person['TABNUM'] !== '' ? $person['TABNUM'] : '—'); ?>]</span>

                    <span class="item-name person-name"><?php echo HTML::chars($person['FULL_NAME']); ?></span>

                    <?php if ( ! empty($shown_cards)): ?>
                        <span class="cards-container">
                            <?php foreach ($shown_cards as $card): ?>
                                <span class="card-badge <?php echo HTML::chars($card['CARD_CLASS']); ?>"
                                      title="<?php echo HTML::chars($card['TYPE_NAME'] . ': ' . $card['ID_CARD']); ?>">
                                    <?php echo HTML::chars($card['TYPE_SHORT']); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($cards) > count($shown_cards)): ?>
                                <span class="card-badge card-badge-default">+<?php echo count($cards) - count($shown_cards); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</li>
