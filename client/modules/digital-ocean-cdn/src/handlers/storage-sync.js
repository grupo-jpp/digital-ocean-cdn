Espo.define("digital-ocean-cdn:handlers/storage-sync", [], function () {
  return {
    actionSyncNow: function (view) {
      var model = view.model;
      if (model.get("type") !== "digitalOceanSpaces") {
        Espo.Ui.warning("Only available for Digital Ocean Spaces storage.");
        return;
      }
      Espo.Ui.notify("Queueing sync...");
      view
        .ajaxPostRequest("DigitalOceanSpaces/action/sync", { id: model.id })
        .then(function (res) {
          Espo.Ui.success(res.message || "Sync queued");
          model.fetch();
        })
        .fail(function () {
          Espo.Ui.error("Sync failed");
        });
    },
  };
});
