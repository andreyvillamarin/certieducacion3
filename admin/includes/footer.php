<?php
// admin/includes/footer.php (ACTUALIZADO para cargar JS al final)
?>
            </div> <!-- Cierre de .container-fluid -->
        </div> <!-- Cierre de #page-content-wrapper -->
    </div> <!-- Cierre de #wrapper -->
    
    <!-- Librería principal de Bootstrap (se carga primero) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script para el menú lateral -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const menuToggle = document.getElementById("menu-toggle");
        if(menuToggle) {
            menuToggle.addEventListener("click", function(e) {
                e.preventDefault();
                document.getElementById("wrapper")?.classList.toggle("toggled");
            });
        }
    });
    </script>
    
    <?php
    // Cargar el script específico de la página si está definido
    if (isset($page_specific_js)) {
        echo '<script src="' . htmlspecialchars($page_specific_js) . '"></script>';
    }
    ?>
</body>
</html>