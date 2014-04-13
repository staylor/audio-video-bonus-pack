<script type="text/html" id="tmpl-av-queue-item">
	<p><strong>Queued for creation:</strong> {{ data.model.path }}</p>
</script>
<script type="text/html" id="tmpl-av-encode-item">
	<p><strong>{{ data.model.type }}</strong> for "{{{ data.model.title }}}" is {{ data.model.progress }}% done.
		<div class="av-encoding-item-bar">
			<div class="av-encoding-item-progress" style="width: {{ data.model.progress }}%;"></div>
		</div>
	</p>
</script>