<?php require_once __DIR__.'/Appearance.php'; require __DIR__.'/appearance-options.php'; $keys=Appearance::keys(); ?>
<button type="button" id="appearance-open" class="appearance-pencil" aria-label="Personalizar painel" title="Personalizar painel"><i class="fas fa-pencil" aria-hidden="true"></i></button>
<dialog id="appearance-dialog" class="tabler-settings offcanvas offcanvas-end" aria-labelledby="appearance-title">
 <header class="offcanvas-header"><div><h2 id="appearance-title" class="offcanvas-title">Personalizar</h2><p>Escolha a aparência e o layout.</p></div><button type="button" class="btn btn-icon" id="appearance-close" aria-label="Fechar personalização"><i class="fas fa-times" aria-hidden="true"></i></button></header>
 <div class="offcanvas-body"><p class="tabler-settings-note">Suas alterações ficam salvas para os usuários deste sistema.</p>
 <?php foreach($tablerSettings as [$key,$legend,$control,$options]): $internal=$keys[$key]; ?>
 <fieldset class="tabler-setting" data-setting="<?=fb_escape($key)?>" data-appearance-group="<?=fb_escape($internal)?>"><legend><?=fb_escape($legend)?></legend><div class="tabler-options tabler-options-<?=fb_escape($control)?>">
 <?php foreach($options as $value=>$label): ?><label class="tabler-option"><input type="radio" name="tabler-<?=fb_escape($key)?>" data-appearance="<?=fb_escape($internal)?>" value="<?=fb_escape(Appearance::value($key,(string)$value))?>"><span class="tabler-option-preview" data-preview-key="<?=fb_escape($key)?>" data-preview-value="<?=fb_escape($value)?>" aria-hidden="true"></span><span><?=fb_escape($label)?></span></label><?php endforeach; ?>
 </div><?php if($internal==='primary'): ?><p id="appearance-status" role="status" aria-live="polite">Carregando preferências…</p><?php endif; ?></fieldset>
 <?php endforeach; ?>
 </div><footer class="tabler-settings-footer"><button type="button" class="btn btn-outline-secondary" id="appearance-reset">Restaurar padrão</button><button type="button" class="btn btn-primary" id="appearance-retry" hidden>Tentar salvar novamente</button></footer>
</dialog>
