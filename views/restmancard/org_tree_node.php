<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Узел дерева отдельной формы организаций (без сотрудников).
 *
 * @var array $node
 * @var int   $level
 */
$has_children = ! empty($node['CHILDREN']);
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

        <?php if ((int) $node['CHILDREN_COUNT'] > 0): ?>
            <span class="badge rc-badge-children" title="<?php echo __('Подразделений'); ?>">📁 <?php echo (int) $node['CHILDREN_COUNT']; ?></span>
        <?php else: ?>
            <span class="badge badge-empty" title="<?php echo __('Подразделений'); ?>">📁 0</span>
        <?php endif; ?>
    </div>

    <ul class="tree-children" style="display: <?php echo ($expanded AND $has_children) ? 'block' : 'none'; ?>;">
        <?php foreach ($node['CHILDREN'] as $child): ?>
            <?php echo View::factory('restmancard/org_tree_node', array(
                'node'  => $child,
                'level' => $level + 1,
            )); ?>
        <?php endforeach; ?>
    </ul>
</li>
