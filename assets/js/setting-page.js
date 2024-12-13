document.addEventListener("DOMContentLoaded", function () {
  const importButton = document.getElementById("my-plugin-import-button");
  const importProgress = document.getElementById("my-plugin-import-progress");
  const importStatus = document.getElementById("my-plugin-import-status");

  const blockSize = 1;
  let offset = 0;
  let totalPosts;
  let importedCount = 0;
  let added = 0;
  let deleted = 0;
  let updated = 0;

  importButton.addEventListener("click", function () {
    importProgress.style.display = "block";
    startImport();
  });

  function fetchWithTimeout(url, options, timeout = 30000) {
    return Promise.race([
      fetch(url, options),
      new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Request timeout')), timeout)
      )
    ]);
  }

  async function chunkImport() {
    try {
      const res = await fetchWithTimeout(ajaxurl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "import_otomoto_adverts_by_packages",
          chunk_size: blockSize,
          offset: offset,
        }),
      });

      if (!res.ok) {
        throw new Error(`HTTP error! Status: ${res.status}`);
      }

      const data = await res.json();

      if (data.success) {
        importedCount += data.data.processed_adverts;
        added += data.data.added;
        deleted += data.data.deleted;
        updated += data.data.updated;
        offset += data.data.processed_adverts;
        importProgress.value = importedCount;
        importStatus.textContent = `Przetworzono ${importedCount}/${totalPosts}`;
        return data.data.processed_adverts;
      } else {
        return 0;
      }
    } catch (error) {
      console.error("Błąd podczas importu:", error);
      importStatus.textContent = "Wystąpił błąd podczas importu. Sprawdź konsolę.";
      return 0;
    }
  }



  async function startImport() {
    // znajdź element o id my-plugin-import-status i ustaw jego tekst na "Importowanie postów..."
    importStatus.textContent = "Importowanie postów...";
    const res = await fetch(ajaxurl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        'X-My-Custom-Header': 'fetch' // własny nagłówek
      },
      body: new URLSearchParams({
        action: "get_adverts_from_otomoto_and_save_json",
      }),
    });

    const data = await res.json();

    if (data.success) {
      importStatus.textContent = "Znaleziono " + data.data.all_adverts + " ogłoszeń. Porównywanie z istniejącymi postami";
      totalPosts = data.data.all_adverts;
      importProgress.max = totalPosts;

      const response = await fetch(ajaxurl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
          'X-My-Custom-Header': 'fetch' // własny nagłówek
        },
        body: new URLSearchParams({
          action: "process_otomoto_adverts",
        }),
      });

      while (importedCount < totalPosts) {
        await chunkImport();
      }
      importProgress.style.display = "none";
      importStatus.textContent = "Import zakończony. Dodano " + added + " postów, zaktualizowano " + updated + " postów, usunięto " + deleted + " postów";
    }
  }
});
