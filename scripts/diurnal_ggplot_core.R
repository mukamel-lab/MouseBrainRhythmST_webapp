# Shared ggplot2/ggh4x renderer for Brainome diurnal plots.
# This is sourced by both the one-shot renderer and the resident R worker.
# It intentionally follows the original R/httpuv app's ggplot construction
# for the main Diurnal Expression tab.

`%||%` <- function(x, y) {
  if (is.null(x) || length(x) == 0 || is.na(x) || identical(x, "")) y else x
}

parse_cli_args <- function(args) {
  out <- list()
  for (arg in args) {
    if (startsWith(arg, "--")) {
      kv <- strsplit(sub("^--", "", arg), "=", fixed = TRUE)[[1]]
      key <- kv[1]
      value <- if (length(kv) > 1) paste(kv[-1], collapse = "=") else ""
      out[[key]] <- value
    }
  }
  out
}

ensure_diurnal_ggplot_packages <- function() {
  if (!requireNamespace("ggplot2", quietly = TRUE)) {
    stop("The R package 'ggplot2' is required for ggplot rendering.", call. = FALSE)
  }
  if (!requireNamespace("ggh4x", quietly = TRUE)) {
    stop("The R package 'ggh4x' is required for ggplot rendering.", call. = FALSE)
  }
  invisible(TRUE)
}

mean_sdl_1 <- function(x) {
  m <- mean(x, na.rm = TRUE)
  s <- stats::sd(x, na.rm = TRUE)
  if (!is.finite(s)) s <- 0
  data.frame(y = m, ymin = m - s, ymax = m + s)
}

render_diurnal_ggplot <- function(args) {
  ensure_diurnal_ggplot_packages()

  obs_path <- args$obs
  pred_path <- args$pred
  color_path <- args$colors
  out_path <- args$out
  plot_gene <- args$gene %||% "Gene"
  color_name <- args$color_name %||% "Group"
  split_by <- trimws(unlist(strsplit(args$split_by %||% "", ",", fixed = TRUE)))
  split_by <- split_by[nzchar(split_by)]
  y_label <- args$y_label %||% "log2 Normalized mRNA Expression"
  x_label <- args$x_label %||% "Zeitgeber Time (double plotted)"
  width <- suppressWarnings(as.numeric(args$width %||% "4.6"))
  height <- suppressWarnings(as.numeric(args$height %||% "3.4"))
  if (!is.finite(width) || width <= 0) width <- 4.6
  if (!is.finite(height) || height <= 0) height <- 3.4

  if (is.null(obs_path) || is.null(pred_path) || is.null(color_path) || is.null(out_path)) {
    stop("Missing --obs, --pred, --colors, or --out argument", call. = FALSE)
  }

  obs <- utils::read.delim(obs_path, stringsAsFactors = FALSE, check.names = FALSE)
  pred <- utils::read.delim(pred_path, stringsAsFactors = FALSE, check.names = FALSE)
  colors_df <- utils::read.delim(color_path, stringsAsFactors = FALSE, check.names = FALSE)

  if (!nrow(obs)) stop("No observation rows were supplied", call. = FALSE)
  if (!nrow(pred)) stop("No prediction rows were supplied", call. = FALSE)

  obs$ZT <- as.numeric(obs$ZT)
  obs$norm_expr <- as.numeric(obs$norm_expr)
  pred$ZT <- as.numeric(pred$ZT)
  pred$pred_expr <- as.numeric(pred$pred_expr)
  obs <- obs[is.finite(obs$ZT) & is.finite(obs$norm_expr), , drop = FALSE]
  pred <- pred[is.finite(pred$ZT) & is.finite(pred$pred_expr), , drop = FALSE]
  if (!nrow(obs) || !nrow(pred)) stop("No finite plot rows were supplied", call. = FALSE)

  # Preserve PHP-provided legend order and colors.
  levels_color <- unique(colors_df$color_label)
  obs$color_label <- factor(obs$color_label, levels = levels_color)
  pred$color_label <- factor(pred$color_label, levels = levels_color)
  cols <- stats::setNames(colors_df$color, colors_df$color_label)

  allowed_split <- c("region", "age", "sex", "genotype")
  split_by <- intersect(split_by, allowed_split)
  for (var in allowed_split) {
    if (var %in% names(obs) && var %in% names(pred)) {
      lvls <- unique(c(obs[[var]], pred[[var]]))
      lvls <- lvls[!is.na(lvls) & nzchar(lvls)]
      obs[[var]] <- factor(obs[[var]], levels = lvls)
      pred[[var]] <- factor(pred[[var]], levels = lvls)
    }
  }

  base_p <- ggplot2::ggplot() +
    ggplot2::annotate("rect", xmin = 0, xmax = 12, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 12, xmax = 24, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 24, xmax = 36, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 36, xmax = 42, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::geom_jitter(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      size = 0.9,
      alpha = 0.35,
      width = 0.35,
      height = 0
    ) +
    ggplot2::stat_summary(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      fun = mean,
      geom = "point",
      size = 2.0
    ) +
    ggplot2::stat_summary(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      fun.data = mean_sdl_1,
      geom = "errorbar",
      width = 0.5
    ) +
    ggplot2::geom_line(
      data = pred,
      ggplot2::aes_string("ZT", "pred_expr", color = "color_label"),
      linewidth = 0.75
    ) +
    ggplot2::scale_x_continuous(breaks = c(0, 12, 24, 36), labels = c("0", "12", "0", "12")) +
    ggplot2::scale_color_manual(values = cols, drop = FALSE) +
    ggplot2::theme_bw(base_size = 12) +
    ggplot2::labs(x = x_label, y = y_label, title = plot_gene, color = color_name)

  if (length(split_by) == 1) {
    base_p <- base_p + ggh4x::facet_nested(stats::as.formula(paste("~", split_by[1])))
  } else if (length(split_by) > 1) {
    base_p <- base_p + ggh4x::facet_nested(stats::as.formula(paste(split_by[1], "~", paste(split_by[-1], collapse = " + "))))
  }

  p <- base_p +
    ggplot2::theme(
      strip.background = ggplot2::element_rect(fill = "white", color = "black", linetype = "blank"),
      strip.text.y.right = ggplot2::element_text(family = "ArialMT", color = "black", size = 12, angle = 270),
      strip.text.x = ggplot2::element_text(family = "ArialMT", color = "black", size = 12),
      axis.title = ggplot2::element_text(family = "ArialMT", color = "black", size = 10),
      plot.title = ggplot2::element_text(family = "ArialMT", color = "black", size = 12, hjust = 0.5, face = "bold.italic"),
      plot.caption = ggplot2::element_text(family = "ArialMT", color = "#666666", size = 9, hjust = 0),
      legend.position = "bottom"
    )

  grDevices::svg(filename = out_path, width = width, height = height, pointsize = 12, bg = "white")
  tryCatch(print(p), finally = grDevices::dev.off())
  invisible(out_path)
}
