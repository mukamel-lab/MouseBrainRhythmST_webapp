# Shared ggplot2/ggh4x/svglite renderers for Brainome plot endpoints.
# Sourced by one-shot render scripts and the resident local R worker.

`%||%` <- function(x, y) {
  if (is.null(x) || length(x) == 0 || anyNA(x) || identical(x, "")) y else x
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

ensure_runtime_ggplot_packages <- function() {
  missing <- c()
  for (pkg in c("ggplot2", "ggh4x", "svglite")) {
    if (!requireNamespace(pkg, quietly = TRUE)) missing <- c(missing, pkg)
  }
  if (length(missing)) {
    stop("Missing required R package(s) for ggplot rendering: ", paste(missing, collapse = ", "), call. = FALSE)
  }
  invisible(TRUE)
}

open_svg_device <- function(filename, width, height, pointsize = 12) {
  # Use svglite rather than grDevices::svg. On shared/headless servers the base
  # SVG device can fail with "unable to start device 'svg'" when cairo support is
  # unavailable. svglite is the robust device for ggplot SVG output.
  svglite::svglite(file = filename, width = width, height = height, bg = "white", pointsize = pointsize)
}

print_svg <- function(plot, out_path, width, height, pointsize = 12) {
  dir.create(dirname(out_path), recursive = TRUE, showWarnings = FALSE)
  open_svg_device(out_path, width, height, pointsize = pointsize)
  on.exit(grDevices::dev.off(), add = TRUE)
  print(plot)
  invisible(out_path)
}

mean_sdl_1 <- function(x) {
  m <- mean(x, na.rm = TRUE)
  s <- stats::sd(x, na.rm = TRUE)
  if (!is.finite(s)) s <- 0
  data.frame(y = m, ymin = m - s, ymax = m + s)
}

sem_data <- function(x) {
  m <- mean(x, na.rm = TRUE)
  s <- stats::sd(x, na.rm = TRUE)
  n <- sum(is.finite(x))
  sem <- if (n > 1 && is.finite(s)) s / sqrt(n) else 0
  data.frame(y = m, ymin = m - sem, ymax = m + sem)
}

stable_jitter <- function(value, amplitude = 0.18) {
  txt <- as.character(value)
  out <- numeric(length(txt))
  for (i in seq_along(txt)) {
    bytes <- utf8ToInt(txt[[i]])
    hash <- 2166136261
    for (b in bytes) {
      hash <- bitwXor(hash, b)
      hash <- (hash * 16777619) %% 4294967296
    }
    out[[i]] <- ((hash %% 10000) / 9999 - 0.5) * amplitude
  }
  out
}

plot_theme <- function(base_size = 8) {
  # Use a generic sans family and plain text faces to avoid browser/system font
  # fallbacks that made the svglite output appear overly italicized on Brainome.
  ggplot2::theme_bw(base_size = base_size, base_family = "sans") +
    ggplot2::theme(
      text = ggplot2::element_text(face = "plain", family = "sans"),
      panel.grid.minor = ggplot2::element_blank(),
      axis.text.x = ggplot2::element_text(angle = 0, hjust = 0.5, face = "plain"),
      axis.text.y = ggplot2::element_text(face = "plain"),
      axis.title.x = ggplot2::element_text(face = "plain"),
      axis.title.y = ggplot2::element_text(face = "plain"),
      legend.position = "bottom",
      legend.title = ggplot2::element_text(size = base_size, face = "plain"),
      legend.text = ggplot2::element_text(size = base_size, face = "plain"),
      plot.title = ggplot2::element_text(hjust = 0.5, face = "bold", size = base_size + 3),
      strip.background = ggplot2::element_blank(),
      strip.text = ggplot2::element_text(face = "plain")
    )
}

render_diurnal_ggplot <- function(args) {
  ensure_runtime_ggplot_packages()

  obs <- utils::read.delim(args$obs, stringsAsFactors = FALSE, check.names = FALSE)
  pred <- utils::read.delim(args$pred, stringsAsFactors = FALSE, check.names = FALSE)
  colors_df <- utils::read.delim(args$colors, stringsAsFactors = FALSE, check.names = FALSE)
  out_path <- args$out
  plot_gene <- args$gene %||% "Gene"
  color_name <- args$color_name %||% "Group"
  split_by <- trimws(unlist(strsplit(args$split_by %||% "", ",", fixed = TRUE)))
  split_by <- split_by[nzchar(split_by)]
  y_label <- args$y_label %||% "log2 Normalized mRNA Expression"
  x_label <- args$x_label %||% "Zeitgeber Time (double plotted)"
  width <- suppressWarnings(as.numeric(args$width %||% "4.4"))
  height <- suppressWarnings(as.numeric(args$height %||% "3.2"))
  if (!is.finite(width) || width <= 0) width <- 4.4
  if (!is.finite(height) || height <= 0) height <- 3.2

  if (!nrow(obs)) stop("No observation rows were supplied", call. = FALSE)
  if (!nrow(pred)) stop("No prediction rows were supplied", call. = FALSE)

  obs$ZT <- as.numeric(obs$ZT)
  obs$norm_expr <- as.numeric(obs$norm_expr)
  pred$ZT <- as.numeric(pred$ZT)
  pred$pred_expr <- as.numeric(pred$pred_expr)
  obs <- obs[is.finite(obs$ZT) & is.finite(obs$norm_expr), , drop = FALSE]
  pred <- pred[is.finite(pred$ZT) & is.finite(pred$pred_expr), , drop = FALSE]
  if (!nrow(obs) || !nrow(pred)) stop("No finite plot rows were supplied", call. = FALSE)

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

  p <- ggplot2::ggplot() +
    ggplot2::annotate("rect", xmin = 0, xmax = 12, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 12, xmax = 24, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 24, xmax = 36, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 36, xmax = 42, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::geom_jitter(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      size = 0.7,
      alpha = 0.28,
      width = 0.28,
      height = 0
    ) +
    ggplot2::stat_summary(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      fun.data = mean_sdl_1,
      geom = "errorbar",
      width = 0.32,
      linewidth = 0.25
    ) +
    ggplot2::stat_summary(
      data = obs,
      ggplot2::aes_string("ZT", "norm_expr", color = "color_label"),
      fun = mean,
      geom = "point",
      size = 1.5
    ) +
    ggplot2::geom_line(
      data = pred,
      ggplot2::aes_string("ZT", "pred_expr", color = "color_label"),
      linewidth = 0.55
    ) +
    ggplot2::scale_x_continuous(breaks = c(0, 12, 24, 36), labels = c("0", "12", "0", "12")) +
    ggplot2::scale_color_manual(values = cols, drop = FALSE) +
    ggplot2::labs(x = x_label, y = y_label, title = plot_gene, color = color_name) +
    plot_theme(base_size = 8) +
    ggplot2::theme(axis.title.y = ggplot2::element_text(face = "plain"))

  if (length(split_by) == 1) {
    p <- p + ggh4x::facet_nested(stats::as.formula(paste("~", split_by[1])))
  } else if (length(split_by) > 1) {
    p <- p + ggh4x::facet_nested(stats::as.formula(paste(split_by[1], "~", paste(split_by[-1], collapse = " + "))))
  }

  print_svg(p, out_path, width, height, pointsize = 10)
  invisible(out_path)
}

render_dv_ggplot <- function(args) {
  ensure_runtime_ggplot_packages()
  obs <- utils::read.delim(args$obs, stringsAsFactors = FALSE, check.names = FALSE)
  out_path <- args$out
  plot_gene <- args$gene %||% "Gene"
  subtitle <- args$subtitle %||% "WT only"
  width <- suppressWarnings(as.numeric(args$width %||% "6"))
  height <- suppressWarnings(as.numeric(args$height %||% "3.8"))
  if (!is.finite(width) || width <= 0) width <- 6
  if (!is.finite(height) || height <= 0) height <- 3.8
  if (!nrow(obs)) stop("No D/V rows were supplied", call. = FALSE)
  obs$value <- as.numeric(obs$value)
  obs <- obs[is.finite(obs$value), , drop = FALSE]
  if (!nrow(obs)) stop("No finite D/V values were supplied", call. = FALSE)
  obs$dv_region <- factor(obs$dv_region, levels = c("Dorsal", "Ventral"))
  obs$x_num <- ifelse(obs$dv_region == "Dorsal", 1, 2)
  obs$x_jitter <- obs$x_num + stable_jitter(obs$jitter_key, 0.25)
  if ("facet_label" %in% names(obs)) {
    obs$facet_label <- ifelse(nzchar(obs$facet_label), obs$facet_label, "Combined")
    obs$facet_label <- factor(obs$facet_label, levels = unique(obs$facet_label))
  }

  p <- ggplot2::ggplot(obs, ggplot2::aes(x = x_num, y = value)) +
    ggplot2::stat_summary(fun = mean, geom = "bar", fill = "#d9e2ec", color = "#2f3a45", width = 0.58) +
    ggplot2::stat_summary(fun.data = sem_data, geom = "errorbar", width = 0.18, linewidth = 0.35, color = "#2f3a45") +
    ggplot2::geom_point(
      data = obs,
      ggplot2::aes(x = x_jitter, y = value),
      inherit.aes = FALSE,
      size = 0.9,
      alpha = 0.22,
      color = "#111827"
    ) +
    ggplot2::scale_x_continuous(breaks = c(1, 2), labels = c("Dorsal", "Ventral")) +
    ggplot2::labs(x = NULL, y = "log2(normalized counts)", title = plot_gene, subtitle = subtitle) +
    plot_theme(base_size = 8) +
    ggplot2::theme(legend.position = "none", axis.title.y = ggplot2::element_text(face = "plain"))

  if ("facet_label" %in% names(obs) && length(unique(obs$facet_label)) > 1) {
    p <- p + ggh4x::facet_wrap2(~facet_label, scales = "free_y")
  }

  print_svg(p, out_path, width, height, pointsize = 10)
  invisible(out_path)
}

render_rostral_caudal_ggplot <- function(args) {
  ensure_runtime_ggplot_packages()
  regions <- utils::read.delim(args$regions, stringsAsFactors = FALSE, check.names = FALSE)
  points <- utils::read.delim(args$points, stringsAsFactors = FALSE, check.names = FALSE)
  summaries <- utils::read.delim(args$summaries, stringsAsFactors = FALSE, check.names = FALSE)
  curves <- utils::read.delim(args$curves, stringsAsFactors = FALSE, check.names = FALSE)
  out_path <- args$out
  plot_gene <- args$gene %||% "Gene"
  subtitle <- args$subtitle %||% ""
  width <- suppressWarnings(as.numeric(args$width %||% "6.3"))
  height <- suppressWarnings(as.numeric(args$height %||% "4.2"))
  if (!is.finite(width) || width <= 0) width <- 6.3
  if (!is.finite(height) || height <= 0) height <- 4.2

  region_levels <- unique(regions$region)
  region_labels <- stats::setNames(regions$label, regions$region)
  region_colors <- stats::setNames(regions$color, regions$region)
  for (dat_name in c("points", "summaries", "curves")) {
    dat <- get(dat_name)
    if ("region" %in% names(dat)) {
      dat$region <- factor(dat$region, levels = region_levels, labels = region_labels[region_levels])
      assign(dat_name, dat)
    }
  }
  color_values <- stats::setNames(as.character(region_colors[region_levels]), as.character(region_labels[region_levels]))

  if (nrow(points)) {
    points$time <- as.numeric(points$time)
    points$value <- as.numeric(points$value)
    points <- points[is.finite(points$time) & is.finite(points$value), , drop = FALSE]
  }
  if (nrow(summaries)) {
    summaries$time <- as.numeric(summaries$time)
    summaries$mean <- as.numeric(summaries$mean)
    summaries$sd <- as.numeric(summaries$sd)
    summaries <- summaries[is.finite(summaries$time) & is.finite(summaries$mean), , drop = FALSE]
  }
  if (nrow(curves)) {
    curves$time <- as.numeric(curves$time)
    curves$value <- as.numeric(curves$value)
    curves <- curves[is.finite(curves$time) & is.finite(curves$value), , drop = FALSE]
  }
  if (!nrow(summaries) && !nrow(curves)) stop("No rostral-caudal plot rows were supplied", call. = FALSE)

  p <- ggplot2::ggplot() +
    ggplot2::annotate("rect", xmin = 0, xmax = 12, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 12, xmax = 24, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 24, xmax = 36, ymin = -Inf, ymax = Inf, fill = "#F6F18F", alpha = 0.1) +
    ggplot2::annotate("rect", xmin = 36, xmax = 42, ymin = -Inf, ymax = Inf, fill = "#606161", alpha = 0.1) +
    ggplot2::geom_line(data = curves, ggplot2::aes(x = time, y = value, color = region), linewidth = 0.55) +
    ggplot2::geom_errorbar(data = summaries, ggplot2::aes(x = time, ymin = mean - sd, ymax = mean + sd, color = region), width = 0.18, linewidth = 0.25) +
    ggplot2::geom_point(data = summaries, ggplot2::aes(x = time, y = mean, color = region), size = 1.6) +
    ggplot2::geom_point(data = points, ggplot2::aes(x = time, y = value, color = region), alpha = 0.18, size = 0.55, position = ggplot2::position_jitter(width = 0.18, height = 0)) +
    ggplot2::scale_x_continuous(breaks = c(0, 12, 24, 36), labels = c("0", "12", "0", "12")) +
    ggplot2::scale_color_manual(values = color_values, drop = FALSE, name = "Cortical position") +
    ggplot2::labs(x = "Zeitgeber Time (double plotted)", y = plot_gene, title = plot_gene, subtitle = subtitle) +
    plot_theme(base_size = 8) +
    ggplot2::theme(axis.title.y = ggplot2::element_text(face = "plain"))

  print_svg(p, out_path, width, height, pointsize = 10)
  invisible(out_path)
}
